<?php

namespace App\Support\Orders;

use App\Support\Pricing\PricedCart;

/**
 * The figures a Customer was shown when they placed an Order — each line's
 * Tier Price, the Delivery Charge and the total — sent with the Checkout and
 * compared at Capture against what the store works out itself (ADR-0029).
 *
 * It exists to be compared and never to be believed: no figure on an Order is
 * ever read from here. Money is in minor units, as the storefront is sent it.
 */
final readonly class Quote
{
    /**
     * @param  array<int, int>  $tierPrices  The Tier Price shown, keyed by Product id.
     */
    public function __construct(
        public array $tierPrices,
        public int $deliveryCharge,
        public int $total,
    ) {}

    /**
     * The Quote as the checkout page posts it.
     *
     * @param  array{lines: list<array{productId: int, tierPrice: int}>, deliveryCharge: int, total: int}  $quote
     */
    public static function fromArray(array $quote): self
    {
        $tierPrices = [];

        foreach ($quote['lines'] as $line) {
            $tierPrices[(int) $line['productId']] = (int) $line['tierPrice'];
        }

        return new self($tierPrices, (int) $quote['deliveryCharge'], (int) $quote['total']);
    }

    /**
     * Whether the store's own figures are exactly the ones shown: the same
     * Products, each at the same Tier Price, the same Delivery Charge and the
     * same total, to the minor unit.
     */
    public function agreesWith(PricedCart $priced): bool
    {
        $tierPrices = [];

        foreach ($priced->lines as $line) {
            $tierPrices[(int) $line->productId] = $line->tierPrice;
        }

        ksort($tierPrices);
        $shown = $this->tierPrices;
        ksort($shown);

        return $shown === $tierPrices
            && $this->deliveryCharge === $priced->deliveryCharge
            && $this->total === $priced->total;
    }
}
