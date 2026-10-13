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
        public int $subtotal,

        /** The Saving: what the MRPs would have come to, less the subtotal. */
        public int $savings,
        public int $deliveryCharge,
        public int $total,

        /** What the subtotal still lacks for free delivery; null once it is free, or where it never is. */
        public ?int $amountToFreeDelivery,
    ) {}

    /**
     * The shape the shared retail worked examples are written in, and the one
     * the TypeScript mirror answers in.
     *
     * @return array{lines: list<array<string, mixed>>, packCount: int, subtotal: int, savings: int, deliveryCharge: int, total: int, amountToFreeDelivery: int|null}
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedPackLine $line): array => $line->toArray(), $this->lines),
            'packCount' => $this->packCount,
            'subtotal' => $this->subtotal,
            'savings' => $this->savings,
            'deliveryCharge' => $this->deliveryCharge,
            'total' => $this->total,
            'amountToFreeDelivery' => $this->amountToFreeDelivery,
        ];
    }
}
