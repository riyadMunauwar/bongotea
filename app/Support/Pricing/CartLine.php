<?php

namespace App\Support\Pricing;

/**
 * One line to be priced: a Product, a whole number of Kilograms, and the
 * Price Override a User set on it, if any.
 */
final readonly class CartLine
{
    public function __construct(
        public ProductPrices $product,
        public int $kilograms,

        /** A Tier Price set by hand, in minor units, kept through any Repricing. */
        public ?int $overridePrice = null,
    ) {}
}
