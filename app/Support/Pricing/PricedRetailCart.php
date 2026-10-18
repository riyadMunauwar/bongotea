<?php

namespace App\Support\Pricing;

/**
 * Every figure a retail Cart, a retail Checkout or a retail Order's Repricing
 * shows, as retail pricing works it out. Money is in minor units.
 */
final readonly class PricedRetailCart
{
    /**
     * @param  list<PricedPackLine>  $lines
     */
    public function __construct(
        public array $lines,

        /** How many Packs, across every line. */
        public int $packCount,

        /** The Packs' weight rounded up to the next whole kilogram, which the Delivery Band is read by; nothing for an empty Cart. */
        public int $kilograms,
        public int $subtotal,

        /** The Saving: what the MRPs would have come to, less the subtotal. */
        public int $savings,

        /** Nothing while no District is known; see the range. */
        public int $deliveryCharge,

        /** Null while no District is known. */
        public ?DeliveryZone $deliveryZone,

        /** Null for an empty Cart, and while no zone is known. */
        public ?DeliveryBand $deliveryBand,

        /**
         * The least and the most the Cart could pay across every zone, shown
         * before a District is known; null for an empty Cart.
         *
         * @var array{least: int, most: int}|null
         */
        public ?array $deliveryRange,
        public int $total,

        /** What the subtotal still lacks for free delivery; null once it is free, or where it never is; before a District is known, null unless every zone agrees. */
        public ?int $amountToFreeDelivery,
    ) {}

    /** What the Order records as its delivery, "Inside Dhaka · 1–2 kg"; null while no zone is known. */
    public function deliveryLabel(): ?string
    {
        return $this->deliveryZone?->labelFor($this->deliveryBand);
    }

    /**
     * The shape the shared retail worked examples are written in, and the one
     * the TypeScript mirror answers in.
     *
     * @return array{lines: list<array<string, mixed>>, packCount: int, kilograms: int, subtotal: int, savings: int, deliveryCharge: int, deliveryZone: string|null, deliveryBand: array{min: int, max: int|null, fee: int}|null, deliveryRange: array{least: int, most: int}|null, total: int, amountToFreeDelivery: int|null}
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedPackLine $line): array => $line->toArray(), $this->lines),
            'packCount' => $this->packCount,
            'kilograms' => $this->kilograms,
            'subtotal' => $this->subtotal,
            'savings' => $this->savings,
            'deliveryCharge' => $this->deliveryCharge,
            'deliveryZone' => $this->deliveryZone?->name,
            'deliveryBand' => $this->deliveryBand?->toArray(),
            'deliveryRange' => $this->deliveryRange,
            'total' => $this->total,
            'amountToFreeDelivery' => $this->amountToFreeDelivery,
        ];
    }
}
