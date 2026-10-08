<?php

namespace App\Support\Pricing;

/**
 * One line as priced: what a Kilogram of it costs in the Cart's Tier, what it
 * would cost without a discount, and what it would cost one Tier up.
 */
final readonly class PricedLine
{
    public function __construct(
        public int|string $productId,
        public int $kilograms,
        public int $regularPrice,
        public int $tierPrice,

        /** The Tier Discount in force, or null where the line pays the Regular Price or was overridden. */
        public ?Discount $discount,
        public bool $overridden,
        public int $lineTotal,

        /** What a Kilogram would cost in the next Tier; an overridden line keeps its price there too. Null at the top Tier. */
        public ?int $nextTierPrice,
    ) {}

    /**
     * @return array{productId: int|string, kilograms: int, regularPrice: int, tierPrice: int, discount: array{type: string, value: int}|null, overridden: bool, lineTotal: int, nextTierPrice: int|null}
     */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'kilograms' => $this->kilograms,
            'regularPrice' => $this->regularPrice,
            'tierPrice' => $this->tierPrice,
            'discount' => $this->discount?->toArray(),
            'overridden' => $this->overridden,
            'lineTotal' => $this->lineTotal,
            'nextTierPrice' => $this->nextTierPrice,
        ];
    }
}
