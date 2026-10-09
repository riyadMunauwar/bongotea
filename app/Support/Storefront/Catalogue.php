<?php

namespace App\Support\Storefront;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * The catalogue as a buyer may see it, read once per request.
 *
 * Every published Product still in the catalogue — the unavailable ones
 * among them, since those are shown greyed — with what a card draws loaded
 * beside it, and the Categories in the Merchant's order. A Page may carry
 * several live Blocks; they all read from here, so the catalogue is queried
 * once however many there are. Bound scoped, so it never outlives the
 * request that read it.
 */
class Catalogue
{
    /** What a card draws beside a Product, loaded with it wherever cards are made. */
    public const CARD_RELATIONS = ['category', 'brand', 'tags', 'media'];

    /** @var Collection<int, Product>|null */
    private ?Collection $products = null;

    /** @var Collection<int, Category>|null */
    private ?Collection $categories = null;

    /**
     * Drafts are nobody's to see and a Product taken out of the catalogue is
     * gone from every list, so neither is here.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        return $this->products ??= Product::query()
            ->published()
            ->with(self::CARD_RELATIONS)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return $this->categories ??= Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    public function productsIn(Category $category): Collection
    {
        return $this->products()->where('category_id', $category->getKey())->values();
    }

    /**
     * Each Category that has a published Product, in the Merchant's order,
     * with its Products beside it.
     *
     * @return list<array{category: Category, products: Collection<int, Product>}>
     */
    public function stocked(): array
    {
        $stocked = [];

        foreach ($this->categories() as $category) {
            $products = $this->productsIn($category);

            if ($products->isNotEmpty()) {
                $stocked[] = ['category' => $category, 'products' => $products];
            }
        }

        return $stocked;
    }

    /**
     * Up to `$count` other teas to go on to from a Product: its own Category
     * first, then the rest of the catalogue.
     *
     * @return Collection<int, Product>
     */
    public function othersBeside(Product $product, int $count = 3): Collection
    {
        $others = $this->products()->reject(fn (Product $other): bool => $other->is($product));

        return $others->where('category_id', $product->category_id)
            ->concat($others->where('category_id', '!=', $product->category_id))
            ->take($count)
            ->values();
    }
}
