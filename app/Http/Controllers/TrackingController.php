<?php

namespace App\Http\Controllers;

use App\Enums\OrderKind;
use App\Enums\TrackingMoment;
use App\Http\Requests\CheckoutRequest;
use App\Models\Pack;
use App\Models\Product;
use App\Models\TrackingContext;
use App\Support\Tracking\MomentRecorder;
use App\Support\Tracking\Selection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * The beacon: where a browser tells the server about a Moment only it saw.
 *
 * It is reached after the page has painted, by `sendBeacon`, with nothing
 * waiting on the answer. The answer is therefore empty, always, and there is
 * nothing in it a browser is expected to read.
 *
 * It is unauthenticated and it must stay cheap, which is the whole of why it
 * is hardened the way it is rather than with a token.
 *
 * What it can write is the Moments a browser alone witnesses — Visit,
 * Product Viewed, Added to Cart and Checkout Started — and nothing else. Capture
 * and Paid are written by the code that captures and verifies, so no
 * Purchase can be forged through here.
 */
class TrackingController extends Controller
{
    /**
     * The longest deduplication id accepted. Longer than anything a browser
     * makes up, short enough that a hand-made request cannot fill a column
     * with an essay. Over it the request is dropped rather than cut: an
     * identifier that has been shortened deduplicates against nothing.
     */
    private const MAX_IDENTIFIER_LENGTH = 100;

    /**
     * The longest list of Products accepted, and the most pairs read out of
     * it: ceilings on the work a hand-made request can ask for. Over the
     * ceiling the list is treated as nothing said — an essay is not a
     * Selection.
     */
    private const MAX_SELECTION_LENGTH = 500;

    private const MAX_SELECTION_ITEMS = 50;

    /**
     * What a User-Agent says when nobody is there.
     *
     * Matched as substrings and case-insensitively. It is a filter and not a
     * defence: a Visit figure is the Merchant's own traffic, and a link
     * preview fetcher is not traffic.
     */
    private const BOT_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'headless',
        'lighthouse', 'pingdom', 'monitor', 'preview', 'fetcher', 'scraper',
    ];

    public function __construct(private readonly MomentRecorder $moments) {}

    /**
     * Record what the browser saw, or record nothing.
     *
     * Every outcome that is not a forgery answers the same empty 204: a
     * Moment a browser may not report, a missing identifier and a crawler are
     * all simply nothing to count, and a beacon has no use for being told
     * which.
     *
     * The one thing answered differently is a request that is not this
     * site's own page talking to us, which is refused outright.
     */
    public function report(Request $request): Response
    {
        $this->refuseUnlessSameOrigin($request);

        $moment = TrackingMoment::fromBrowser($request->input('moment'));
        $identifier = $this->identifier($request);

        if ($moment === null || $identifier === null || $this->looksLikeABot($request)) {
            return $this->taken();
        }

        $this->moments->reportedByBrowser(
            $moment,
            $identifier,

            /**
             * The address a Provider is told about is the page the visitor
             * was on, which a same-origin beacon carries as its Referer.
             */
            TrackingContext::fromRequest($request, $request->headers->get('referer')),

            $moment->isAboutProductsNamed() ? $this->selection($request) : null,
        );

        return $this->taken();
    }

    /**
     * `Sec-Fetch-Site` is the only forgery signal available here: this route
     * carries no session, so there is no token to compare. A browser sets the
     * header itself and a page cannot lie about it. A client that omits it is
     * refused rather than trusted, and that costs the Moment its server copy
     * and nothing else: the Pixel on the page has already fired.
     */
    private function refuseUnlessSameOrigin(Request $request): void
    {
        abort_unless($request->header('Sec-Fetch-Site') === 'same-origin', ResponseCode::HTTP_FORBIDDEN);
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
     * The Products a Moment is about: the one whose page was opened, the one
     * added to the Cart, or the Cart the Checkout was opened with.
     *
     * The browser names Products and Kilograms, and the prices come from the
     * catalogue. Nothing that arrives may say what anything costs, so a
     * hand-made post can name a hundred things and still report only what
     * the store charges for the published ones it actually sells.
     */
    private function selection(Request $request): Selection
    {
        $items = $request->input('items');

        $retail = $request->input('cart') === Selection::RETAIL;

        if (! is_string($items) || strlen($items) > self::MAX_SELECTION_LENGTH) {
            return $retail ? Selection::forPacks(collect(), []) : Selection::forProducts(collect(), []);
        }

        if ($retail) {
            $counts = $this->quantities($items, CheckoutRequest::MAX_PACKS);

            /** Only Packs the store sells খুচরা, as `forProducts()` reads only published Products sold পাইকারি. */
            $packs = Pack::query()
                ->whereKey(array_keys($counts))
                ->where('serves_retail', true)
                ->whereHas('product', fn (Builder $products): Builder => $products->published()->soldAs(OrderKind::Retail))
                ->orderBy('id')
                ->get();

            return Selection::forPacks($packs, $counts);
        }

        $kilograms = $this->quantities($items, CheckoutRequest::MAX_KILOGRAMS);

        return Selection::forProducts(Product::query()->published()->soldAs(OrderKind::Bulk)->findMany(array_keys($kilograms)), $kilograms);
    }

    /**
     * The `id:quantity` pairs the browser posted — Products and Kilograms, or
     * for the packet basket Packs and counts — read forgivingly: a pair that
     * is not two numbers is dropped, and a quantity outside what its Cart
     * holds is brought back inside it. A Moment reported with one Product's
     * count corrected is still the Moment that happened.
     *
     * @return array<int, int>
     */
    private function quantities(string $items, int $most): array
    {
        $kilograms = [];

        foreach (array_slice(array_filter(explode(',', $items)), 0, self::MAX_SELECTION_ITEMS) as $item) {
            [$product, $weight] = array_pad(explode(':', $item, 2), 2, '1');

            if (! ctype_digit($product) || $product === '0') {
                continue;
            }

            $kilograms[(int) $product] = max(1, min($most, ctype_digit($weight) ? (int) $weight : 1));
        }

        return $kilograms;
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
     * The one answer: taken, with nothing in it.
     */
    private function taken(): Response
    {
        return response('', ResponseCode::HTTP_NO_CONTENT);
    }
}
