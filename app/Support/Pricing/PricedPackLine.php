<?php

namespace App\Support\Pricing;

/**
 * One retail line as priced: its Pack Price times its count, and the Saving
 * measured from the Pack's MRP. There is no Tier and no quantity break.
 */
final readonly class PricedPackLine
{
    public function __construct(
        public int|string $packId,
        public int $count,
        public int $mrp,
        public int $packPrice,
        public bool $overridden,
        public int $lineTotal,

        /** The MRP less the Pack Price, times the count. */
        public int $savings,
    ) {}

    /**
     * @return array{packId: int|string, count: int, mrp: int, packPrice: int, overridden: bool, lineTotal: int, savings: int}
     */
    public function toArray(): array
    {
        return [
            'packId' => $this->packId,
            'count' => $this->count,
            'mrp' => $this->mrp,
            'packPrice' => $this->packPrice,
            'overridden' => $this->overridden,
            'lineTotal' => $this->lineTotal,
            'savings' => $this->savings,
        ];
    }
}
