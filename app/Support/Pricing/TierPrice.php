<?php

namespace App\Support\Pricing;

/**
 * What one Kilogram of a Product costs in one Tier, and the Tier Discount
 * that price comes from — null where it is the Regular Price.
 */
final readonly class TierPrice
{
    public function __construct(
        public Tier $tier,
        public int $price,
        public ?Discount $discount,
    ) {}
}
