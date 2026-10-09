<?php

namespace App\Support\Pricing;

/**
 * One of a Product's ranges of the Cart's total Kilograms, and the Tier Price
 * that Product charges within it, in minor units (ADR-0031).
 */
final readonly class Tier
{
    public function __construct(
        public int $min,

        /** Null for the last Tier, which is open-ended. */
        public ?int $max,
        public int $price,
    ) {}

    public function contains(int $kilograms): bool
    {
        return $kilograms >= $this->min && ($this->max === null || $kilograms <= $this->max);
    }

    /** The range as the admin writes it: "6–10 kg", "51+ kg". */
    public function label(): string
    {
        return $this->max === null ? "{$this->min}+ kg" : "{$this->min}–{$this->max} kg";
    }

    /**
     * The range alone, which is what a priced line names.
     *
     * @return array{min: int, max: int|null}
     */
    public function range(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }

    /**
     * @return array{min: int, max: int|null, price: int}
     */
    public function toArray(): array
    {
        return [...$this->range(), 'price' => $this->price];
    }
}
