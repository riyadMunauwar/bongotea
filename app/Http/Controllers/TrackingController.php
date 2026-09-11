<?php

namespace App\Http\Controllers;

use App\Enums\TrackingMoment;
use App\Models\Page;
use App\Models\TrackingContext;
use App\Support\Tracking\MomentRecorder;
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

        $this->moments->reportedByBrowser($page, $moment, $identifier, TrackingContext::fromRequest(
            $request,
            route('landing.show', $page->slug),
        ));

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
