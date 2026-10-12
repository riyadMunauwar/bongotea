<?php

namespace App\Support\Pages\Blocks;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Tag;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: every published Product, one panel per Category in its
 * Category Theme, with filter chips for the Tags those Products carry and,
 * once more than one Brand has published Products, for Brands too. The
 * Merchant writes only the headings; the filtering is done in the browser
 * over what is sent.
 *
 * A panel carries only a Category's first few why points, in the Merchant's
 * order, so it stays short above its Products; the Category's own page shows
 * them all.
 */
class CategoryPanels extends PageBlock
{
    public const WHY_POINTS_SHOWN = 3;

    public static function type(): string
    {
        return 'category_panels';
    }

    public static function label(): string
    {
        return 'Category product panels';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedRectangleStack;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(60),
            TextInput::make('heading')->maxLength(160),
        ];
    }

    public static function props(array $data): array
    {
        $catalogue = app(Catalogue::class);
        $products = $catalogue->products();

        $tags = $products->flatMap(fn (Product $product) => $product->tags)
            ->unique('id')
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name])
            ->values()
            ->all();

        $brands = $products->map(fn (Product $product): Brand => $product->brand)
            ->unique('id')
            ->sortBy('name')
            ->map(fn (Brand $brand): array => ['id' => $brand->id, 'name' => $brand->name])
            ->values()
            ->all();

        $categories = [];

        foreach ($catalogue->stocked() as ['category' => $category, 'products' => $inCategory]) {
            $detail = CatalogueProps::category($category, $inCategory);

            $categories[] = [
                ...$detail,
                'whyPoints' => array_slice($detail['whyPoints'], 0, self::WHY_POINTS_SHOWN),
                'products' => CatalogueProps::cards($inCategory),
            ];
        }

        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'tags' => $tags,
            'brands' => count($brands) > 1 ? $brands : [],
            'categories' => $categories,
        ];
    }
}
