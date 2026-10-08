<?php

namespace App\Support\Pricing;

/**
 * How far the Cart is from the next Tier, and what reaching it would save on
 * the Kilograms already in it — never a guess at what might be added.
 */
final readonly class NextTier
{
    public function __construct(
        public Tier $tier,
        public int $kilogramsToGo,
        public int $extraSaving,
    ) {}

    /**
     * @return array{tier: array{key: string, min: int, max: int|null}, kilogramsToGo: int, extraSaving: int}
     */
    public function toArray(): array
    {
        return ['tier' => $this->tier->toArray(), 'kilogramsToGo' => $this->kilogramsToGo, 'extraSaving' => $this->extraSaving];
    }
}
