<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Shortcodes\ShortcodeExpander;
use App\Support\PageCache\FallbackPageCacheReader;
use App\Support\PageCache\StaticPageCache;
use App\Support\Tracking\TrackingSnippet;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

class LandingPageController extends Controller
{
    public function __construct(
        private readonly StaticPageCache $cache,
        private readonly ShortcodeExpander $expander,
        private readonly TrackingSnippet $snippet,
    ) {}

    /**
     * Renders a published landing page and writes it to the static cache on
     * the way out.
     *
     * This runs on a cache miss only. Once the file exists, one of the cache's
     * readers answers every subsequent request for the slug from disk and this
     * method is not reached again until the Page is saved.
     *
     * The response is marked as a full render, which is how the panel tells a
     * merchant whose webserver serves the cache from one whose does not.
     */
    public function show(string $slug): Response
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        $rendered = $this->expander->expand($page);

        /**
         * The Pixel goes in here, at the one point every byte a visitor
         * receives passes through: after expansion, and before the same
         * string is both written to the cache and returned. Injected rather
         * than authored, because a Page's body is the whole document and a
         * Merchant who forgot to paste something would otherwise report
         * nothing and have no way to tell.
         *
         * It is stable at render time — it depends on the Page and its
         * Destinations and on nothing about the visitor — which is what lets
         * it be frozen into a file served to everyone. A Destination being
         * saved forgets every cached Page, so a changed dataset never
         * outlives the edit.
         *
         * A Page that reports nowhere is injected with nothing and is served
         * byte for byte as it was written.
         */
        $html = $this->snippet->injectedInto($rendered->html, $page);

        /**
         * public/page-cache/<slug>.html is served by the webserver
         * publish-blind, so only a published Page may ever be written there —
         * and only when every shortcode on it rendered. A page that failed is
         * served live and left out of the cache rather than frozen onto disk
         * for everyone.
         */
        if ($rendered->cacheable) {
            $this->cache->put($page->slug, $html);
        }

        /**
         * No-store on the miss response itself. The visitor who paid for the
         * render should not also hold a private copy that outlives an edit: the
         * shared file on disk is the one source of truth, and the observers
         * invalidate that.
         */
        return response($html, ResponseCode::HTTP_OK)
            ->header('Cache-Control', 'no-store')
            ->header(FallbackPageCacheReader::HEADER, FallbackPageCacheReader::MISS_MARKER);
    }

    /**
     * The authoring preview: the exact same expansion pipeline, behind admin
     * auth, never written to the cache.
     *
     * A preview that renders differently is not a preview, so this deliberately
     * shares `expand()` rather than reimplementing it. Without this route the
     * merchant's only way to see their page is to publish it — which is also
     * the act that makes it reachable to ad traffic.
     *
     * It carries no Pixel, for the reason the Processing Page carries none:
     * a Merchant looking at their own draft is not a Visit, and reporting
     * one would put the admin's own loads into the Merchant's Conversion
     * Rate and their dataset. Nothing visual is lost — a Pixel draws
     * nothing.
     */
    public function preview(Page $page): Response
    {
        return response($this->expander->expand($page)->html, ResponseCode::HTTP_OK)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
