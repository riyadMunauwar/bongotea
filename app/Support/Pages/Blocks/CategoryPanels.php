<?php

namespace App\Support\Pages\Blocks;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Tag;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * A live Block: every published Product, one band per Category in its
 * Category Theme, with filter chips for the Tags those Products carry and,
 * once more than one Brand has published Products, for Brands too. The
 * Merchant writes the headings and chooses how many cards a row holds on a
 * wide screen and how many each band shows at a time; the filtering and the
 * "load more" are done in the browser over what is sent.
 *
 * A band heads its Products with a Category's name, count, price range and
 * quality headline alone, so its cards come soon; what the Category says
 * about itself and its why points are left to the Category's own page.
 */
class CategoryPanels extends PageBlock
{
    public const COLUMNS = [3, 4, 5];

    public const DEFAULT_COLUMNS = 4;

    public const BATCH_SIZE = 8;

    public const BATCH_SIZE_MAX = 48;

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
            Select::make('columns')
                ->label('Cards across, on a wide screen')
                ->options(array_combine(self::COLUMNS, self::COLUMNS))
                ->default((string) self::DEFAULT_COLUMNS)
                ->selectablePlaceholder(false)
                ->helperText('A phone shows two across, a tablet three.'),
            TextInput::make('batch_size')
                ->label('Cards shown at a time')
                ->integer()
                ->minValue(1)
                ->maxValue(self::BATCH_SIZE_MAX)
                ->default(self::BATCH_SIZE)
                ->helperText('Each Category shows this many, and "load more" shows this many again.'),
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
            $categories[] = [
                ...Arr::except(CatalogueProps::category($category, $inCategory), ['about', 'whyPoints']),
                'products' => CatalogueProps::cards($inCategory),
            ];
        }

        $columns = is_numeric($data['columns'] ?? null) ? (int) $data['columns'] : self::DEFAULT_COLUMNS;
        $batchSize = is_numeric($data['batch_size'] ?? null) ? (int) $data['batch_size'] : self::BATCH_SIZE;

        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'columns' => in_array($columns, self::COLUMNS, true) ? $columns : self::DEFAULT_COLUMNS,
            'batchSize' => max(1, min(self::BATCH_SIZE_MAX, $batchSize)),
            'tags' => $tags,
            'brands' => count($brands) > 1 ? $brands : [],
            'categories' => $categories,
        ];
    }
}
