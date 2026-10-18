<?php

namespace App\Support\Pricing;

use App\Models\Pack;

/**
 * What retail pricing needs to know about a Pack, and nothing else: its Pack
 * Price and the MRP its Saving is measured from, which the Pack Price never
 * exceeds (ADR-0034), and its weight, which the Delivery Band is read by
 * (ADR-0039).
 */
final readonly class PackPrices
{
    public function __construct(
        public int|string $id,
        public int $mrp,
        public int $price,
        public int $grams,
    ) {}

    public static function of(Pack $pack): self
    {
        return new self($pack->getKey(), $pack->mrp, $pack->price, $pack->grams);
    }
}
