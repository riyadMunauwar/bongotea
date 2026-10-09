<?php

namespace App\Support\Storefront;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The catalogue searched as a buyer types: every word must turn up in
 * something the Product is known by — its name, its short description, its
 * Category, its Brand, a Tag, or the search keywords the Merchant gave it
 * for other spellings ("primium") — in any case.
 *
 * `whereLike` asks each database for a case-insensitive match its own way
 * (`ILIKE` on PostgreSQL, `LIKE` on SQLite), so no SQL here is written for
 * one driver. A LIKE wildcard typed by the buyer is dropped rather than
 * escaped, because SQLite honours no escape character unless told one.
 */
class ProductSearch
{
    public const SUGGESTIONS = 5;

    /**
     * @return Collection<int, Product>
     */
    public function matching(string $query, ?int $limit = null): Collection
    {
        $words = self::words($query);

        if ($words === []) {
            return new Collection;
        }

        $search = Product::query()
            ->published()
            ->with(Catalogue::CARD_RELATIONS)
            ->orderBy('id');

        foreach ($words as $word) {
            $pattern = "%{$word}%";

            $search->where(fn (Builder $matches) => $matches
                ->whereLike('name', $pattern)
                ->orWhereLike('short_description', $pattern)
                ->orWhereLike('search_keywords', $pattern)
                ->orWhereHas('category', fn (Builder $category) => $category->whereLike('name', $pattern))
                ->orWhereHas('brand', fn (Builder $brand) => $brand->whereLike('name', $pattern))
                ->orWhereHas('tags', fn (Builder $tag) => $tag->whereLike('name', $pattern)));
        }

        return $search->limit($limit)->get();
    }

    /**
     * @return list<string>
     */
    private static function words(string $query): array
    {
        $plain = str_replace(['%', '_', '\\'], ' ', $query);

        return array_values(array_filter(preg_split('/\s+/u', trim($plain)) ?: [], fn (string $word): bool => $word !== ''));
    }
}
