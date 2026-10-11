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
        public array $lines,
        public int $totalKilograms,
        public int $subtotal,

        /** The Saving: what the MRPs would have come to, less the subtotal. */
        public int $savings,
        public int $deliveryCharge,

        /** Null for an empty Cart, which no band covers. */
        public ?DeliveryBand $deliveryBand,
        public int $total,

        /** The subtotal per Kilogram, rounded half-up to the minor unit; null for an empty Cart. */
        public ?int $averagePerKilogram,

        /** Null where no line already in the Cart gets any cheaper. */
        public ?PriceDrop $nextPriceDrop,

        /** Null once delivery is free, or where no band ahead of the Cart is. */
        public ?int $kilogramsToFreeDelivery,
    ) {}

    /**
     * The shape the shared worked examples are written in, and the one the
     * TypeScript mirror answers in.
     *
     * @return array{lines: list<array<string, mixed>>, totalKilograms: int, subtotal: int, savings: int, deliveryCharge: int, deliveryBand: array{min: int, max: int|null, fee: int}|null, total: int, averagePerKilogram: int|null, nextPriceDrop: array{atKilograms: int, kilogramsToGo: int, extraSaving: int}|null, kilogramsToFreeDelivery: int|null}
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $line): array => $line->toArray(), $this->lines),
            'totalKilograms' => $this->totalKilograms,
            'subtotal' => $this->subtotal,
            'savings' => $this->savings,
            'deliveryCharge' => $this->deliveryCharge,
            'deliveryBand' => $this->deliveryBand?->toArray(),
            'total' => $this->total,
            'averagePerKilogram' => $this->averagePerKilogram,
            'nextPriceDrop' => $this->nextPriceDrop?->toArray(),
            'kilogramsToFreeDelivery' => $this->kilogramsToFreeDelivery,
        ];
    }
}
