<?php

namespace App\Support\PageCache;

use App\Models\Page;
use App\Models\Product;

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
    public function __construct(private readonly StaticPageCache $cache) {}

    public function forgetPage(Page $page): void
    {
        $this->cache->forget($page->slug);

        /**
         * A renamed page leaves its old file behind, which nginx would keep
         * serving on the retired URL forever with no model pointing at it.
         */
        if ($page->wasChanged('slug') && is_string($original = $page->getOriginal('slug'))) {
            $this->cache->forget($original);
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
}
