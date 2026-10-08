<?php

namespace App\Support\Pricing;

use App\Enums\DiscountType;

/**
 * The Tier Discount a line is charged under: a whole percentage, or a fixed
 * amount per Kilogram in minor units.
 */
final readonly class Discount
{
    public function __construct(
        public DiscountType $type,
        public int $value,
    ) {}

    /**
     * The Regular Price less this Discount. A percentage rounds half-up to the
     * whole taka, worked in integers so the TypeScript mirror lands on the
     * same figure; a fixed amount is plain subtraction, never below nothing.
     */
    public function applyTo(int $regularPrice, int $minorUnitsPerTaka): int
    {
        if ($this->type === DiscountType::Fixed) {
            return max(0, $regularPrice - $this->value);
        }

        $hundredths = $regularPrice * (100 - $this->value);
        $hundredthsPerTaka = 100 * $minorUnitsPerTaka;

        return max(0, intdiv($hundredths + intdiv($hundredthsPerTaka, 2), $hundredthsPerTaka) * $minorUnitsPerTaka);
    }

    /**
     * @return array{type: string, value: int}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value];
    }
}
