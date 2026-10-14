<?php

namespace App\Support\Pages\Blocks;

use App\Models\Category;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: one tile per Category that has published Products, in its
 * Category Theme, saying how many teas it has and the lowest price any of
 * them reaches at its last Tier. The Merchant writes only the heading and
 * the "see all" link; the figures are read from the catalogue each time.
 */
class CategoryTiles extends PageBlock
{
    public static function type(): string
    {
        return 'category_tiles';
    }

    public static function label(): string
    {
        return 'Category tiles';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->maxLength(120),
            TextInput::make('all_label')->label('"See all" link text')->maxLength(60),
            self::linkInput('all_link', '"See all" link'),
        ];
    }

    public static function props(array $data): array
    {
        $tiles = [];

        foreach (app(Catalogue::class)->stocked() as ['category' => $category, 'products' => $products]) {
            /** @var Category $category */
            $tiles[] = [
                ...CatalogueProps::categoryReference($category),
                'note' => CatalogueProps::text($category->note),
                'productCount' => $products->count(),
                'lowestPrice' => CatalogueProps::priceRange($products)['low'] ?? null,
            ];
        }

        return [
            'heading' => self::text($data, 'heading'),
            'allLink' => self::button($data, 'all_label', 'all_link'),
            'tiles' => $tiles,
        ];
    }
}
