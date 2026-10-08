<?php

namespace App\Support\Pricing;

/**
 * Every figure a Cart, a Checkout or an Order's Repricing shows, as the
 * pricing module works it out. Money is in minor units.
 */
final readonly class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public Tier $tier,
        public array $lines,
        public int $totalKilograms,
        public int $subtotal,

        /** What the Regular Prices would have come to, less the subtotal. */
        public int $savings,
        public int $deliveryCharge,

        /** Null for an empty Cart, which no band covers. */
        public ?DeliveryBand $deliveryBand,
        public int $total,

        /** The subtotal per Kilogram, rounded half-up to the minor unit; null for an empty Cart. */
        public ?int $averagePerKilogram,

        /** Null at the top Tier. */
        public ?NextTier $nextTier,

        /** Null once delivery is free, or where no band ahead of the Cart is. */
        public ?int $kilogramsToFreeDelivery,
    ) {}

    /**
     * The shape the shared worked examples are written in, and the one the
     * TypeScript mirror answers in.
     *
     * @return array{tier: array{key: string, min: int, max: int|null}, lines: list<array<string, mixed>>, totalKilograms: int, subtotal: int, savings: int, deliveryCharge: int, deliveryBand: array{min: int, max: int|null, fee: int}|null, total: int, averagePerKilogram: int|null, nextTier: array{tier: array{key: string, min: int, max: int|null}, kilogramsToGo: int, extraSaving: int}|null, kilogramsToFreeDelivery: int|null}
     */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier->toArray(),
            'lines' => array_map(fn (PricedLine $line): array => $line->toArray(), $this->lines),
            'totalKilograms' => $this->totalKilograms,
            'subtotal' => $this->subtotal,
            'savings' => $this->savings,
            'deliveryCharge' => $this->deliveryCharge,
            'deliveryBand' => $this->deliveryBand?->toArray(),
            'total' => $this->total,
            'averagePerKilogram' => $this->averagePerKilogram,
            'nextTier' => $this->nextTier?->toArray(),
            'kilogramsToFreeDelivery' => $this->kilogramsToFreeDelivery,
        ];
    }
}
