<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Page;
use App\Shortcodes\ShortcodeExpander;
use App\Support\PageCache\FallbackPageCacheReader;
use App\Support\PageCache\StaticPageCache;
use App\Support\Pages\CanonicalLink;
use App\Support\Pages\FrontPage;
use App\Support\Tracking\TrackingSnippet;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

class LandingPageController extends Controller
{
    public function __construct(
        private readonly StaticPageCache $cache,
        private readonly ShortcodeExpander $expander,
        private readonly TrackingSnippet $snippet,
        private readonly CanonicalLink $canonical,
        private readonly FrontPage $frontPage,
    ) {}

    /**
     * Renders a published landing page and writes it to the static cache on
     * the way out.
     *
     * This runs on a cache miss only. Once the file exists, one of the cache's
     * readers answers every subsequent request for the slug from disk and this
     * method is not reached again until the Page is saved.
     *
     * Where this Page is the Front Page, the same render also fills the Site
     * Root's entry — so a visitor arriving at `/promo` warms `/` too, and the
     * two can never hold different bytes.
     */
    public function show(string $slug): Response
    {
        return $this->serve(Page::query()->published()->where('slug', $slug)->firstOrFail());
    }

    /**
     * The Site Root: the Front Page a merchant designated, or the Placeholder.
     *
     * This runs on a cache miss only, like `show()`. Once `_front.html` exists,
     * the webserver or the fallback reader answers `/` from disk and none of
     * this runs again until something invalidates it.
     *
     * A Front Page that is a draft, in the bin, or gone answers with nothing,
     * and the Placeholder is served and cached in its place — so the Site Root
     * is never the one address on the site that can 404, and the setting is
     * remembered meanwhile.
     */
    public function front(): Response
    {
        $page = $this->frontPage->designated();

        if ($page !== null) {
            return $this->serve($page);
        }

        /**
         * Cached like anything else, so `/` has one serving story whatever the
         * installation has configured. It is therefore one document for
         * everyone — there is no session on this path and the bytes on disk are
         * the same bytes for every visitor — which is why it says what it has
         * to say to the Merchant out loud rather than conditionally.
         */
        $html = view('placeholder')->render();

        $this->cache->putFront($html);

        return $this->cacheMiss($html);
    }

    /**
     * Render a published Page, write it to the cache, and hand it back.
     *
     * The one path both addresses go through, which is what makes the Site
     * Root's copy a copy: whichever address misses first renders **once** and
     * writes both entries from the same string, so `<slug>.html` and
     * `_front.html` cannot drift (ADR-0023). Rendering separately per address
     * would leave two documents produced at two moments with nothing holding
     * them equal and no test able to see it.
     */
    private function serve(Page $page): Response
    {
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
        /**
         * The canonical goes in beside the Pixel, at the same one point, for
         * the reason a Front Page exists at all: these bytes may answer at two
         * addresses, and a crawler left to choose between them may pick the
         * Site Root, which is a setting rather than an identity. Drawn on every
         * Page and never only on the Front Page — the copy requires that
         * nothing in the bytes knows which Page was designated.
         *
         * Before the Pixel, and that order matters. Both insert themselves at
         * `</head>`, so whichever runs last ends up closest to it — and the
         * Pixel is the one with a reason to be: it is meant to fire before the
         * page paints, and `TrackingVisitTest` pins it as the last thing in the
         * head. A canonical is metadata a crawler reads at its leisure and has
         * no such claim, so it yields the position.
         */
        $html = $this->canonical->injectedInto($rendered->html, $page);

        $html = $this->snippet->injectedInto($html, $page);

        /**
         * public/page-cache/<slug>.html is served by the webserver
         * publish-blind, so only a published Page may ever be written there —
         * and only when every shortcode on it rendered. A page that failed is
         * served live and left out of the cache rather than frozen onto disk
         * for everyone.
         */
        if ($rendered->cacheable) {
            $this->cache->put($page->slug, $html);

            /**
             * The same string, in the same call. This is the whole of the byte
             * copy: one render, two filenames.
             */
            if ($this->frontPage->isDesignated($page)) {
                $this->cache->putFront($html);
            }
        }

        return $this->cacheMiss($html);
    }

    /**
     * A full render's response.
     *
     * No-store on the miss response itself. The visitor who paid for the render
     * should not also hold a private copy that outlives an edit: the shared file
     * on disk is the one source of truth, and the observers invalidate that.
     *
     * Marked as a full render, which is how the panel tells a merchant whose
     * webserver serves the cache from one whose does not.
     */
    private function cacheMiss(string $html): Response
    {
        return response($html, ResponseCode::HTTP_OK)
            ->header('Cache-Control', 'no-store')
            ->header(FallbackPageCacheReader::HEADER, FallbackPageCacheReader::MISS_MARKER);
    }

    /**
     * The authoring preview: the exact same expansion pipeline, behind the
     * `author pages` Permission, never written to the cache.
     *
     * Gated here rather than on the route, so the check names the typed
     * Permission case the Pages resource is gated by — a User who cannot open
     * the Pages screens has no reason to reach a draft through its back door
     * either. An Owner passes through `can()`'s own bypass.
     *
     * A preview that renders differently is not a preview, so this deliberately
     * shares `expand()` rather than reimplementing it. Without this route the
     * merchant's only way to see their page is to publish it — which is also
     * the act that makes it reachable to ad traffic.
     *
     * It carries neither of the two things injected into a served Page, and
     * for different reasons.
     *
     * No Pixel, for the reason the Processing Page carries none: a Merchant
     * looking at their own draft is not a Visit, and reporting one would put
     * the admin's own loads into the Merchant's Conversion Rate and their
     * dataset. Nothing visual is lost — a Pixel draws nothing.
     *
     * No canonical, because this address is not one: the preview is `noindex,
     * nofollow` and sits behind auth, so there is no duplicate for a crawler to
     * choose between and nothing to point it at. "The exact same expansion
     * pipeline" above is about expansion, which this does share; both of these
     * happen after it.
     */
    public function preview(Page $page): Response
    {
        abort_unless(auth()->user()?->can(Permission::AuthorPages) ?? false, ResponseCode::HTTP_FORBIDDEN);

        return response($this->expander->expand($page)->html, ResponseCode::HTTP_OK)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
