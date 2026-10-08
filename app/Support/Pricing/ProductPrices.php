<?php

namespace App\Support\Pricing;

use App\Enums\DiscountType;
use App\Models\Product;

/**
 * What the pricing module needs to know about a Product, and nothing else:
 * its Regular Price, its discount kind, and a Tier Discount per Tier key.
 * A Tier the Product says nothing about is simply absent here.
 */
final readonly class ProductPrices
{
    /**
     * @param  array<string, int|null>  $tierDiscounts  Keyed by Tier key; a percentage, or minor units per Kilogram.
     */
    public function __construct(
        public int|string $id,
        public int $regularPrice,
        public DiscountType $discountType,
        public array $tierDiscounts,
    ) {}

    public static function of(Product $product): self
    {
        return new self(
            id: $product->getKey(),
            regularPrice: (int) $product->price,
            discountType: $product->discount_type ?? DiscountType::Percent,
            tierDiscounts: $product->tier_discounts ?? [],
        );
    }

    /** The Discount this Product names for the Tier, or null where it names none. */
    public function discountFor(Tier $tier): ?Discount
    {
        $value = $this->tierDiscounts[$tier->key] ?? null;

        return $value === null ? null : new Discount($this->discountType, (int) $value);
    }
}
