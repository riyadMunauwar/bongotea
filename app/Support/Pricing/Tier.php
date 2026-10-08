<?php

namespace App\Support\Pricing;

/**
 * One range of the Cart's total Kilograms, deciding which of its prices
 * every Product charges.
 *
 * The key is what a Product's Tier Discounts are stored against, so a Tier
 * keeps its Discounts when the Merchant moves its edges, and a Tier added in
 * Settings starts with none.
 */
final readonly class Tier
{
    public function __construct(
        public string $key,
        public int $min,

        /** Null for the last Tier, which is open-ended. */
        public ?int $max,
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
     * @return array{key: string, min: int, max: int|null}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'min' => $this->min, 'max' => $this->max];
    }
}
