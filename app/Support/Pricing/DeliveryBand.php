<?php

namespace App\Support\Pricing;

/**
 * One range of an Order's total Kilograms and the Delivery Charge for it, in
 * minor units. Its own table: where a band's edge falls says nothing about
 * where a price changes.
 */
final readonly class DeliveryBand
{
    public function __construct(
        public int $min,

        /** Null for the last band, which is open-ended. */
        public ?int $max,
        public int $fee,
    ) {}

    public function contains(int $kilograms): bool
    {
        return $kilograms >= $this->min && ($this->max === null || $kilograms <= $this->max);
    }

    public function isFree(): bool
    {
        return $this->fee === 0;
    }

    /**
     * @return array{min: int, max: int|null, fee: int}
     */
    public function toArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'fee' => $this->fee];
    }
}
