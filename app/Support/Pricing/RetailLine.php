<?php

namespace App\Support\Pricing;

/**
 * One retail line to be priced: a Pack, how many of it, and the Price
 * Override a User set on it, if any.
 */
final readonly class RetailLine
{
    public function __construct(
        public PackPrices $pack,
        public int $count,

        /** A Pack Price set by hand, in minor units, kept through any Repricing. */
        public ?int $overridePrice = null,
    ) {}
}
