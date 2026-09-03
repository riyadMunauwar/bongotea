<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Shortcodes\ShortcodeExpander;
use App\Support\PageCache\FallbackPageCacheReader;
use App\Support\PageCache\StaticPageCache;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

class LandingPageController extends Controller
{
    public function __construct(
        private readonly StaticPageCache $cache,
        private readonly ShortcodeExpander $expander,
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
         * public/page-cache/<slug>.html is served by nginx publish-blind, so
         * only a published Page may ever be written there — and only when
         * every shortcode on it rendered. A page that failed is served live and
         * left out of the cache rather than frozen onto disk for everyone.
         */
        if ($rendered->cacheable) {
            $this->cache->put($page->slug, $rendered->html);
        }

        /**
         * No-store on the miss response itself. The visitor who paid for the
         * render should not also hold a private copy that outlives an edit: the
         * shared file on disk is the one source of truth, and the observers
         * invalidate that.
         */
        return response($rendered->html, ResponseCode::HTTP_OK)
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
     */
    public function preview(Page $page): Response
    {
        return response($this->expander->expand($page)->html, ResponseCode::HTTP_OK)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
