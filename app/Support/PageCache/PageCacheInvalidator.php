<?php

namespace App\Support\PageCache;

use App\Models\Page;
use App\Models\Product;
use App\Support\Pages\FrontPage;

/**
 * The one place that knows which URLs a change kills.
 *
 * v1 runs on the file and database cache drivers, so there are no cache tags
 * and no "flush everything touching product X" primitive to lean on — every
 * invalidation has to name its URLs. The page↔product pivot is the dependency
 * index tags would have given us, so no separate index table is needed.
 */
class PageCacheInvalidator
{
    public function __construct(
        private readonly StaticPageCache $cache,
        private readonly FrontPage $frontPage,
    ) {}

    public function forgetPage(Page $page): void
    {
        $this->cache->forget($page->slug);

        /**
         * A renamed page leaves its old file behind, and whichever path is
         * serving the cache — the webserver, or the fallback reader — keeps
         * answering the retired URL from it forever, with no model pointing
         * at it.
         */
        if ($page->wasChanged('slug') && is_string($original = $page->getOriginal('slug'))) {
            $this->cache->forget($original);
        }

        /**
         * The Site Root holds a copy of the Front Page's bytes, so it has to be
         * forgotten alongside them. This is the sharpest bug this feature has
         * if it is missed, and it fails silently: the Merchant unpublishes,
         * `/summer-sale` correctly goes away, and `/` goes on serving the
         * unpublished Page to everyone for as long as it stands, with the admin
         * showing it as a draft.
         *
         * Asked through `isDesignated()` rather than `designated()` on purpose.
         * The moments that matter here — unpublished, binned, force-deleted —
         * are exactly the moments the Page has stopped being servable, so a
         * check that only recognised a *published* Front Page would skip every
         * one of them.
         */
        if ($this->frontPage->isDesignated($page)) {
            $this->cache->forgetFront();
        }
    }

    /**
     * Clear every Page that renders this Product. Covers a price change, a
     * rename, and the soft-delete that turns a live checkout into the
     * unavailable comment.
     */
    public function forgetPagesFor(Product $product): void
    {
        $product->pages()->withTrashed()->pluck('slug')
            ->each(fn (string $slug) => $this->cache->forget($slug));
    }

    /**
     * Clear every Page there is. What a saved Setting calls for: the currency
     * symbol, the decimals and the quantity ceiling are read while a Page is
     * rendered, so there is no Page a change to them leaves intact.
     */
    public function forgetEveryPage(): void
    {
        $this->cache->flush();
    }
}
