<?php

namespace App\Support\Pages\Blocks;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * A live Block: a sideways row of product cards, filled from one Category,
 * one Tag, one Brand, or Products picked by hand in the order picked. The
 * Block stores only which and how many; the cards are read from the
 * catalogue each time, so a draft or a Product taken out of the catalogue
 * simply drops out, and a Category since deleted leaves the row empty.
 */
class ProductRail extends PageBlock
{
    public const LIMIT = 8;

    public const LIMIT_MAX = 24;

    public static function type(): string
    {
        return 'product_rail';
    }

    public static function label(): string
    {
        return 'Product rail';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedViewColumns;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(60),
            TextInput::make('heading')->maxLength(160),
            Select::make('source')
                ->label('Show')
                ->options([
                    'category' => 'Products in a Category',
                    'tag' => 'Products with a Tag',
                    'brand' => 'Products of a Brand',
                    'picked' => 'Products picked by hand',
                ])
                ->default('category')
                ->required()
                ->live(),
            Select::make('category_id')
                ->label('Category')
                ->options(fn (): array => Category::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('source') === 'category')
                ->required(fn (Get $get): bool => $get('source') === 'category'),
            Select::make('tag_id')
                ->label('Tag')
                ->options(fn (): array => Tag::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('source') === 'tag')
                ->required(fn (Get $get): bool => $get('source') === 'tag'),
            Select::make('brand_id')
                ->label('Brand')
                ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('source') === 'brand')
                ->required(fn (Get $get): bool => $get('source') === 'brand'),
            Select::make('product_ids')
                ->label('Products')
                ->multiple()
                ->searchable()
                ->options(fn (): array => Product::query()->published()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => $get('source') === 'picked')
                ->required(fn (Get $get): bool => $get('source') === 'picked')
                ->helperText('Shown in the order picked.'),
            TextInput::make('limit')
                ->label('How many at most')
                ->integer()
                ->minValue(1)
                ->maxValue(self::LIMIT_MAX)
                ->default(self::LIMIT),
            TextInput::make('link_label')->label('Link text')->maxLength(60),
            self::linkInput('link'),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'link' => self::button($data, 'link_label', 'link'),
            'products' => CatalogueProps::cards(self::products($data)->take(self::limit($data))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, Product>
     */
    private static function products(array $data): Collection
    {
        $products = app(Catalogue::class)->products();

        return match ($data['source'] ?? null) {
            'category' => $products->where('category_id', self::id($data, 'category_id'))->values(),
            'brand' => $products->where('brand_id', self::id($data, 'brand_id'))->values(),
            'tag' => $products->filter(fn (Product $product): bool => $product->tags->contains('id', self::id($data, 'tag_id')))->values(),
            'picked' => self::picked($products, $data),
            default => new Collection,
        };
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $data
     * @return Collection<int, Product>
     */
    private static function picked(Collection $products, array $data): Collection
    {
        $byId = $products->keyBy('id');
        $ids = is_array($data['product_ids'] ?? null) ? $data['product_ids'] : [];

        return new Collection(array_values(array_filter(array_map(
            fn (mixed $id): ?Product => is_numeric($id) ? $byId->get((int) $id) : null,
            $ids,
        ))));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function id(array $data, string $key): ?int
    {
        return is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function limit(array $data): int
    {
        $limit = is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : self::LIMIT;

        return max(1, min(self::LIMIT_MAX, $limit));
    }
}
