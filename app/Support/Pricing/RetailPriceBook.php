<?php

namespace App\Support\Pricing;

use App\Support\Settings\DeliveryChargeSettings;
use InvalidArgumentException;

/**
 * Retail pricing: the one authority on what a retail Cart costs (ADR-0034),
 * beside the bulk `PriceBook` and never inside it.
 *
 * Each line is its Pack Price times its count, with no Tier and no quantity
 * break, and the Cart pays one flat retail Delivery Charge, waived once the
 * subtotal reaches the free-delivery threshold; with no threshold it is never
 * waived. The storefront runs a TypeScript mirror of the same arithmetic
 * (`resources/js/lib/retail-pricing.ts`) held to it by the shared worked
 * examples in `tests/Fixtures/Pricing/retail-examples.json`.
 */
final readonly class RetailPriceBook
{
    public function __construct(
        public int $deliveryCharge,

        /** The subtotal at which delivery is free; null for never. */
        public ?int $freeDeliveryFrom,
    ) {}

    /** The retail Delivery Charge and threshold as the Merchant has them in Settings. */
    public static function current(): self
    {
        $settings = app(DeliveryChargeSettings::class);

        return new self($settings->retailDeliveryCharge, $settings->retailFreeDeliveryFrom);
    }

    /**
     * @param  list<RetailLine>  $lines
     */
    public function price(array $lines): PricedRetailCart
    {
        $pricedLines = [];
        $packCount = 0;
        $subtotal = 0;
        $savings = 0;

        foreach ($lines as $line) {
            if ($line->count < 0) {
                throw new InvalidArgumentException('A line cannot hold fewer than no Packs.');
            }

            if ($line->overridePrice !== null && $line->overridePrice < 0) {
                throw new InvalidArgumentException('A Price Override cannot be below nothing.');
            }

            if ($line->overridePrice !== null && $line->overridePrice > $line->pack->mrp) {
                throw new InvalidArgumentException("A Price Override cannot be above Pack {$line->pack->id}'s MRP.");
            }

            $packPrice = $line->overridePrice ?? $line->pack->price;
            $lineSavings = ($line->pack->mrp - $packPrice) * $line->count;

            $pricedLines[] = new PricedPackLine(
                packId: $line->pack->id,
                count: $line->count,
                mrp: $line->pack->mrp,
                packPrice: $packPrice,
                overridden: $line->overridePrice !== null,
                lineTotal: $packPrice * $line->count,
                savings: $lineSavings,
            );

            $packCount += $line->count;
            $subtotal += $packPrice * $line->count;
            $savings += $lineSavings;
        }

        $isFree = $this->freeDeliveryFrom !== null && $subtotal >= $this->freeDeliveryFrom;
        $deliveryCharge = $packCount === 0 || $isFree ? 0 : $this->deliveryCharge;

        return new PricedRetailCart(
            lines: $pricedLines,
            packCount: $packCount,
            subtotal: $subtotal,
            savings: $savings,
            deliveryCharge: $deliveryCharge,
            total: $subtotal + $deliveryCharge,
            amountToFreeDelivery: $this->freeDeliveryFrom === null || $isFree ? null : $this->freeDeliveryFrom - $subtotal,
        );
    }
}
