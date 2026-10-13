<?php

namespace App\Support\Orders;

use App\Support\Pricing\PricedRetailCart;

/**
 * The figures a Customer was shown when they placed a retail Order — each
 * line's Pack Price, the retail Delivery Charge and the total — sent with the
 * Checkout and compared at Capture against what the store works out itself
 * (ADR-0029, ADR-0034). Like the bulk Quote it is compared and never
 * believed, and the Saving is not part of it. Money in minor units.
 */
final readonly class RetailQuote
{
    /**
     * @param  array<int, int>  $packPrices  The Pack Price shown, keyed by Pack id.
     */
    public function __construct(
        public array $packPrices,
        public int $deliveryCharge,
        public int $total,
    ) {}

    /**
     * The Quote as the checkout page posts it for the packet basket.
     *
     * @param  array{lines: list<array{packId: int, packPrice: int}>, deliveryCharge: int, total: int}  $quote
     */
    public static function fromArray(array $quote): self
    {
        $packPrices = [];

        foreach ($quote['lines'] as $line) {
            $packPrices[(int) $line['packId']] = (int) $line['packPrice'];
        }

        return new self($packPrices, (int) $quote['deliveryCharge'], (int) $quote['total']);
    }

    /**
     * Whether the store's own figures are exactly the ones shown: the same
     * Packs, each at the same Pack Price, the same Delivery Charge and the
     * same total, to the minor unit.
     */
    public function agreesWith(PricedRetailCart $priced): bool
    {
        $packPrices = [];

        foreach ($priced->lines as $line) {
            $packPrices[(int) $line->packId] = $line->packPrice;
        }

        ksort($packPrices);
        $shown = $this->packPrices;
        ksort($shown);

        return $shown === $packPrices
            && $this->deliveryCharge === $priced->deliveryCharge
            && $this->total === $priced->total;
    }
}
