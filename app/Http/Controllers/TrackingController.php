<?php

namespace App\Http\Controllers;

use App\Enums\TrackingMoment;
use App\Models\Page;
use App\Models\TrackingContext;
use App\Support\Settings\GeneralSettings;
use App\Support\Tracking\MomentRecorder;
use App\Support\Tracking\Selection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * The beacon: where a browser tells the server about a Moment only it saw.
 *
 * A published Page is served by the webserver as a file with no PHP running,
 * so this is the one thing on a Visit's path that touches the framework at
 * all — and it is reached after the page has painted, by `sendBeacon`, with
 * nothing waiting on the answer. The answer is therefore empty, always, and
 * there is nothing in it a browser is expected to read.
 *
 * It is unauthenticated and it must stay cheap, which is the whole of why it
 * is hardened the way it is rather than with a token. A token baked into
 * cached HTML either never expires — and is trivially replayable, buying
 * nothing — or expires and silently kills tracking on every Page the cache
 * has not refreshed since.
 *
 * What it can write is Visit and Checkout Started and nothing else. Capture
 * and Paid are written by the code that captures and verifies, so no
 * Purchase can be forged through here.
 */
class TrackingController extends Controller
{
    /**
     * The longest deduplication id accepted. Longer than anything the
     * snippet makes up, short enough that a hand-made request cannot fill a
     * column with an essay. Over it the request is dropped rather than cut:
     * an identifier that has been shortened deduplicates against nothing, so
     * keeping it would cost the double-counting the whole mechanism exists
     * to prevent.
     */
    private const MAX_IDENTIFIER_LENGTH = 100;

    /**
     * The longest list of ticked Products accepted, and the most pairs read
     * out of it.
     *
     * Both are ceilings on the work a hand-made request can ask for rather
     * than rules about a Page: what a Page actually sells is settled below by
     * reading the Products back off it, so a list naming a hundred ids adds no
     * line and no charge either way. Over the ceiling the list is treated as
     * nothing said — an essay is not a Selection — and the Moment reports what
     * the Page's Checkout opens on.
     */
    private const MAX_SELECTION_LENGTH = 500;

    private const MAX_SELECTION_ITEMS = 50;

    /**
     * What a User-Agent says when nobody is there.
     *
     * Matched as substrings and case-insensitively, which is how every one
     * of these is conventionally spotted. It is a filter and not a defence:
     * a crawler that runs script and names itself a browser is counted, and
     * the list only has to catch the ones that announce themselves — a Visit
     * figure is the Merchant's own traffic, and a link preview fetcher is
     * not traffic.
     */
    private const BOT_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'headless',
        'lighthouse', 'pingdom', 'monitor', 'preview', 'fetcher', 'scraper',
    ];

    public function __construct(private readonly MomentRecorder $moments) {}

    /**
     * Record what the browser saw, or record nothing.
     *
     * Every outcome that is not a forgery answers the same empty 204: an
     * unknown slug, an unpublished Page, a Moment a browser may not report
     * and a crawler are all simply nothing to count, and a beacon has no use
     * for being told which. Answering them alike also means nothing about an
     * installation's Pages can be read back out of this endpoint.
     *
     * The one thing answered differently is a request that is not this
     * site's own page talking to us, which is refused outright.
     */
    public function report(Request $request): Response
    {
        $this->refuseUnlessSameOrigin($request);

        $moment = TrackingMoment::fromBrowser($request->input('moment'));
        $identifier = $this->identifier($request);

        /**
         * Everything answerable from the request itself first, so that
         * nothing but a real visitor on a real Page costs a query. This sits
         * on the path of every ad click, and cheapness is the only reason it
         * can.
         */
        if ($moment === null || $identifier === null || $this->looksLikeABot($request)) {
            return $this->taken();
        }

        $page = $this->publishedPage($request);

        if ($page === null) {
            return $this->taken();
        }

        $this->moments->reportedByBrowser(
            $page,
            $moment,
            $identifier,
            TrackingContext::fromRequest($request, route('landing.show', $page->slug)),
            $moment === TrackingMoment::CheckoutStarted ? $this->selection($request, $page) : null,
        );

        return $this->taken();
    }

    /**
     * `Sec-Fetch-Site` is the same signal the Checkout POST already trusts
     * for request forgery, and it is the only one available here: this route
     * carries no session, so there is no token to compare and nothing a
     * cross-site post could steal by being allowed through.
     *
     * A browser sets the header itself and a page cannot lie about it. A
     * client that omits it — which some in-app webviews do — is refused
     * rather than trusted, and that costs the Visit its server copy and
     * nothing else: the Pixel on the page has already fired.
     */
    private function refuseUnlessSameOrigin(Request $request): void
    {
        abort_unless($request->header('Sec-Fetch-Site') === 'same-origin', ResponseCode::HTTP_FORBIDDEN);
    }

    /**
     * The published Page the beacon says it came from, or null.
     *
     * Read back from the slug rather than trusted, which is what keeps a
     * hand-made post from writing rows against a Page that was never
     * published or never existed.
     */
    private function publishedPage(Request $request): ?Page
    {
        $slug = $request->input('slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Page::query()->published()->where('slug', $slug)->first();
    }

    /**
     * The identifier the browser deduplicated its own copy on.
     */
    private function identifier(Request $request): ?string
    {
        $identifier = $request->input('id');

        if (! is_string($identifier)) {
            return null;
        }

        $identifier = trim($identifier);

        return $identifier !== '' && strlen($identifier) <= self::MAX_IDENTIFIER_LENGTH ? $identifier : null;
    }

    /**
     * What the visitor had ticked when they started filling the Checkout.
     *
     * The browser names Products and counts, and the prices come from the
     * Page — exactly as a Delivery Charge is derived from the Page at
     * Capture. Nothing that arrives may say what anything costs, so a
     * hand-made post can name a hundred things and still report what this
     * Page charges for the ones it actually sells.
     *
     * A request that names no Products at all reports what the Page's
     * Checkout opens on: its Default Selection where it asks the Customer to
     * choose, everything it sells where it does not. That is the figure the
     * form's first paint shows, and so it is the right one for a visitor who
     * has chosen nothing yet.
     */
    private function selection(Request $request, Page $page): Selection
    {
        $items = $request->input('items');

        if (! is_string($items) || strlen($items) > self::MAX_SELECTION_LENGTH) {
            return Selection::forProducts($page->productsOpenWith(), []);
        }

        $quantities = $this->quantities($items);

        return Selection::forProducts($page->productsChosen(array_keys($quantities)), $quantities);
    }

    /**
     * The `id:quantity` pairs the browser posted, read forgivingly: a pair
     * that is not two numbers is dropped, and a quantity outside what a
     * Checkout would take is brought back inside it.
     *
     * Forgiving rather than refusing, because this is a report and not a
     * Capture. An Order is refused over a figure that does not add up; a
     * Moment reported with one Product's count corrected is still the Moment
     * that happened.
     *
     * @return array<int, int>
     */
    private function quantities(string $items): array
    {
        $maximum = app(GeneralSettings::class)->checkoutMaxQuantity;
        $quantities = [];

        foreach (array_slice(array_filter(explode(',', $items)), 0, self::MAX_SELECTION_ITEMS) as $item) {
            [$product, $quantity] = array_pad(explode(':', $item, 2), 2, '1');

            if (! ctype_digit($product) || $product === '0') {
                continue;
            }

            $quantities[(int) $product] = max(1, min($maximum, ctype_digit($quantity) ? (int) $quantity : 1));
        }

        return $quantities;
    }

    private function looksLikeABot(Request $request): bool
    {
        $userAgent = strtolower((string) $request->userAgent());

        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one answer: taken, with nothing in it. Whether a row was written
     * is not the browser's business and there is nothing it could do about
     * it either way.
     */
    private function taken(): Response
    {
        return response('', ResponseCode::HTTP_NO_CONTENT);
    }
}
