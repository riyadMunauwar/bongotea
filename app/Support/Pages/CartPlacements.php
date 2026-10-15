<?php

namespace App\Support\Pages;

use App\Models\Page;
use App\Support\Pages\Blocks\CartBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which Page each Cart stands on (ADR-0038): the Page holding a Block that
 * draws it. A Cart stands on one Page at most, so its link is that Page's
 * path, and one on no Page has none.
 *
 * Read from the Pages' stored Blocks each time it is asked, never cached: a
 * store has a handful of Pages, and a Cart placed a moment ago must be found.
 */
class CartPlacements
{
    /**
     * The Block kinds that draw a Cart, and so place it on their Page. A
     * Block that only names a Cart — a Price Calculator working with one —
     * does not.
     *
     * @return list<string>
     */
    public static function blockTypes(): array
    {
        return [CartBlock::type()];
    }

    /**
     * The Carts these Blocks draw, in order, a Cart drawn twice named twice.
     *
     * @param  list<array{type?: string, data?: array<string, mixed>}>|null  $blocks
     * @return list<int>
     */
    public static function cartIdsIn(?array $blocks): array
    {
        $ids = [];

        foreach ($blocks ?? [] as $block) {
            $cartId = $block['data']['cart_id'] ?? null;

            if (in_array($block['type'] ?? null, self::blockTypes(), true) && is_numeric($cartId)) {
                $ids[] = (int) $cartId;
            }
        }

        return $ids;
    }

    /**
     * Every placed Cart's Page, by Cart id, draft Pages included: a Cart on
     * a draft Page stands there, though nobody can reach it yet.
     *
     * @return Collection<int, Page>
     */
    public static function pages(?int $exceptPageId = null): Collection
    {
        $placed = collect();

        Page::query()
            ->when($exceptPageId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptPageId))
            ->orderBy('id')
            ->get()
            ->each(function (Page $page) use ($placed): void {
                foreach (self::cartIdsIn($page->blocks) as $cartId) {
                    if (! $placed->has($cartId)) {
                        $placed->put($cartId, $page);
                    }
                }
            });

        return $placed;
    }

    /** The Page a Cart stands on, leaving one Page out of the search, or null. */
    public static function pageOf(int $cartId, ?int $exceptPageId = null): ?Page
    {
        return self::pages($exceptPageId)->get($cartId);
    }
}
