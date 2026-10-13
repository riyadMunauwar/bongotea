<?php

namespace App\Support\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Support\Pricing\Tier;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Products and Categories as the storefront draws them.
 *
 * Every key is mapped by hand, so nothing stored reaches a page unread. A
 * characteristic the Merchant left blank goes as null (or an empty list), so
 * the component draws no empty box for it. Prices go as a Product's MRP and own
 * Tiers in minor units, exactly as the pricing module reads them, so the
 * Cart's mirror of the arithmetic can price straight from a card.
 *
 * @phpstan-type ThemeTokens array{text: string, tint: string, panel: string, panelText: string, panelSubtext: string, accent: string, pillBackground: string, pillText: string, tickBackground: string, line: string}
 * @phpstan-type ProductCardProps array{id: int, name: string, href: string, shortDescription: ?string, imageUrl: ?string, badge: ?string, strength: array{grade: string, bars: int}|null, isAvailable: bool, brand: array{id: int, name: string}, category: array{id: int, name: string, slug: string, href: string, theme: ThemeTokens}, tagIds: list<int>, mrp: int, tiers: list<array{min: int, max: ?int, price: int}>}
 */
class CatalogueProps
{
    /**
     * @return ProductCardProps
     */
    public static function card(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'href' => self::productPath($product),
            'shortDescription' => self::text($product->short_description),
            'imageUrl' => $product->coverUrl(),
            'badge' => self::text($product->badge),
            'strength' => $product->strength === null ? null : ['grade' => $product->strength->grade(), 'bars' => $product->strength->bars()],
            'isAvailable' => $product->is_available,
            'brand' => ['id' => $product->brand->id, 'name' => $product->brand->name],
            'category' => self::categoryReference($product->category),
            'tagIds' => $product->tags->pluck('id')->sort()->values()->all(),
            'mrp' => $product->mrp,
            'tiers' => array_map(fn (Tier $tier): array => $tier->toArray(), $product->priceTiers()),
        ];
    }

    /**
     * @param  iterable<Product>  $products
     * @return list<ProductCardProps>
     */
    public static function cards(iterable $products): array
    {
        return collect($products)->map(fn (Product $product): array => self::card($product))->values()->all();
    }

    /**
     * What the Cart prices a Product from: its MRP and own Tiers, and whether a buyer
     * can have it now.
     *
     * @return array{id: int, isAvailable: bool, mrp: int, tiers: list<array{min: int, max: ?int, price: int}>}
     */
    public static function cartProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'isAvailable' => $product->is_available,
            'mrp' => $product->mrp,
            'tiers' => array_map(fn (Tier $tier): array => $tier->toArray(), $product->priceTiers()),
        ];
    }

    /**
     * Each Category that has a published Product, in the Merchant's order,
     * with its cards: how the Cart's Blocks list every tea.
     *
     * @return list<array<string, mixed>>
     */
    public static function stockedCategories(Catalogue $catalogue): array
    {
        $categories = [];

        foreach ($catalogue->stocked() as ['category' => $category, 'products' => $products]) {
            $categories[] = [
                ...self::categoryReference($category),
                'note' => self::text($category->note),
                'products' => self::cards($products),
            ];
        }

        return $categories;
    }

    /**
     * A Product as one line of the header search's live dropdown.
     *
     * @return array{name: string, href: string, imageUrl: ?string, categoryName: string, firstTierPrice: int}
     */
    public static function suggestion(Product $product): array
    {
        return [
            'name' => $product->name,
            'href' => self::productPath($product),
            'imageUrl' => $product->coverUrl(),
            'categoryName' => $product->category->name,
            'firstTierPrice' => self::firstTierPrice($product),
        ];
    }

    /**
     * A Product's own page: its card, and everything else the Merchant filled in.
     *
     * @return array<string, mixed>
     */
    public static function detail(Product $product): array
    {
        $html = filled($product->description) ? RichContentRenderer::make($product->description)->toHtml() : '';

        return [
            ...self::card($product),
            'descriptionHtml' => trim(strip_tags($html)) === '' ? null : $html,
            'gallery' => $product->getMedia(Product::GALLERY_COLLECTION)
                ->map(fn (Media $media): string => $media->getFullUrl())
                ->values()
                ->all(),
            'teaType' => self::text($product->tea_type),
            'grade' => self::text($product->grade),
            'particleSize' => self::text($product->particle_size),
            'liquorColour' => self::text($product->liquor_colour),
            'liquorBody' => self::text($product->liquor_body),
            'aroma' => self::text($product->aroma),
            'infusion' => self::text($product->infusion),
            'cupsPerKilogram' => self::text($product->cups_per_kilogram),
            'packageWeight' => self::text($product->package_weight),
            'customCharacteristics' => self::customCharacteristics($product->custom_characteristics),
            'bestFor' => self::texts($product->best_for),
            'notBestFor' => self::text($product->not_best_for),
            'brewingSteps' => self::texts($product->brewing_steps),
            'businessTips' => self::texts($product->business_tips),
            'storageTips' => self::storageTips($product->storage_tips),
        ];
    }

    /**
     * A Category in full, with what its published Products add up to: how
     * many there are, and their prices from the cheapest last Tier to the
     * dearest first Tier.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     */
    public static function category(Category $category, Collection $products): array
    {
        return [
            ...self::categoryReference($category),
            'note' => self::text($category->note),
            'qualityHeadline' => self::text($category->quality_headline),
            'about' => self::text($category->about),
            'whyPoints' => self::texts($category->why_points),
            'productCount' => $products->count(),
            'priceRange' => self::priceRange($products),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{low: int, high: int}|null
     */
    public static function priceRange(Collection $products): ?array
    {
        if ($products->isEmpty()) {
            return null;
        }

        return [
            'low' => $products->min(fn (Product $product): int => self::lastTierPrice($product)),
            'high' => $products->max(fn (Product $product): int => self::firstTierPrice($product)),
        ];
    }

    /** What a Kilogram of the Product costs in its first Tier. */
    public static function firstTierPrice(Product $product): int
    {
        return $product->priceTiers()[0]->price;
    }

    /** What a Kilogram of the Product costs at its heaviest, cheapest Tier. */
    public static function lastTierPrice(Product $product): int
    {
        $tiers = $product->priceTiers();

        return $tiers[array_key_last($tiers)]->price;
    }

    public static function productPath(Product $product): string
    {
        return '/tea/'.$product->slug;
    }

    public static function categoryPath(Category $category): string
    {
        return '/category/'.$category->slug;
    }

    /**
     * @return array{id: int, name: string, slug: string, href: string, theme: ThemeTokens}
     */
    public static function categoryReference(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'href' => self::categoryPath($category),
            'theme' => $category->theme()->tokens(),
        ];
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The Merchant's own Characteristics, in their order; a row missing its
     * label or its value says nothing, so it is not sent.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function customCharacteristics(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $characteristics = [];

        foreach ($rows as $row) {
            $label = self::text($row['label'] ?? null);
            $value = self::text($row['value'] ?? null);

            if ($label !== null && $value !== null) {
                $characteristics[] = ['label' => $label, 'value' => $value];
            }
        }

        return $characteristics;
    }

    /**
     * The Product's Storage tips, in its order; a tip missing its heading or
     * its explanation is half a sentence, so it is not sent.
     *
     * @return list<array{heading: string, explanation: string}>
     */
    private static function storageTips(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $tips = [];

        foreach ($rows as $row) {
            $heading = self::text($row['heading'] ?? null);
            $explanation = self::text($row['explanation'] ?? null);

            if ($heading !== null && $explanation !== null) {
                $tips[] = ['heading' => $heading, 'explanation' => $explanation];
            }
        }

        return $tips;
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $value): ?string => self::text($value), $values)));
    }
}
