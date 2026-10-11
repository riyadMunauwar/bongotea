<?php

namespace App\Support\Pricing;

/**
 * One line as priced: what a Kilogram of it costs for the Cart's weight, the
 * MRP its Saving is measured from, and which of its Product's Tiers the price
 * came from.
 */
final readonly class PricedLine
{
    public function __construct(
        public int|string $productId,
        public int $kilograms,
        public int $mrp,
        public int $tierPrice,

        /** The Tier the price came from; null on an overridden line, whose reason says why instead. */
        public ?Tier $tier,
        public bool $overridden,
        public int $lineTotal,
    ) {}

    /**
     * @return array{productId: int|string, kilograms: int, mrp: int, tierPrice: int, tier: array{min: int, max: int|null}|null, overridden: bool, lineTotal: int}
     */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'kilograms' => $this->kilograms,
            'mrp' => $this->mrp,
            'tierPrice' => $this->tierPrice,
            'tier' => $this->tier?->range(),
            'overridden' => $this->overridden,
            'lineTotal' => $this->lineTotal,
        ];
    }
}
