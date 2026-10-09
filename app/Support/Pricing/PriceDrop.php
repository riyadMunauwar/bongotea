<?php

namespace App\Support\Pricing;

/**
 * The nearest Cart weight at which a line already in the Cart gets cheaper,
 * and what reaching it would save on the Kilograms already there, across every
 * line that drops at it — never a guess at what might be added (ADR-0031).
 */
final readonly class PriceDrop
{
    public function __construct(
        public int $atKilograms,
        public int $kilogramsToGo,
        public int $extraSaving,
    ) {}

    /**
     * @return array{atKilograms: int, kilogramsToGo: int, extraSaving: int}
     */
    public function toArray(): array
    {
        return ['atKilograms' => $this->atKilograms, 'kilogramsToGo' => $this->kilogramsToGo, 'extraSaving' => $this->extraSaving];
    }
}
