<?php

namespace App\Support\Pricing;

use App\Support\Settings\DeliveryChargeSettings;
use InvalidArgumentException;
use LogicException;

/**
 * The pricing module: the one authority on what a Cart costs (ADR-0031).
 *
 * Each Product owns its Tiers, and every line is priced from the Tier of its
 * own Product that the Cart's total Kilograms — across every line — fall in;
 * the Delivery Charge is read from its own table by the same total. The
 * Checkout's Capture, Repricing and an Order taken by phone all ask this, and
 * the storefront runs a TypeScript mirror of the same arithmetic
 * (`resources/js/lib/pricing.ts`) held to it by the shared worked examples in
 * `tests/Fixtures/Pricing/examples.json`.
 *
 * A Product's Tiers and the Delivery Bands are both assumed to cover every
 * whole kilogram from one without gap or overlap, which the Product and the
 * Delivery Charges settings refuse to save otherwise.
 */
final readonly class PriceBook
{
    /**
     * @param  list<DeliveryBand>  $deliveryBands  In order, the first starting at one, the last open-ended.
     */
    public function __construct(
        public array $deliveryBands,
    ) {}

    /** The Delivery Bands as the Merchant has them in Settings. */
    public static function current(): self
    {
        return new self(array_map(
            fn (array $band): DeliveryBand => new DeliveryBand($band['min'], $band['max'], $band['fee']),
            app(DeliveryChargeSettings::class)->deliveryBands,
        ));
    }

    /**
     * @param  list<CartLine>  $lines
     */
    public function price(array $lines): PricedCart
    {
        $totalKilograms = 0;

        foreach ($lines as $line) {
            if ($line->kilograms < 0) {
                throw new InvalidArgumentException('A line cannot hold fewer than no Kilograms.');
            }

            if ($line->overridePrice !== null && $line->overridePrice < 0) {
                throw new InvalidArgumentException('A Price Override cannot be below nothing.');
            }

            if ($line->overridePrice !== null && $line->overridePrice > $line->product->mrp) {
                throw new InvalidArgumentException("A Price Override cannot be above Product {$line->product->id}'s MRP.");
            }

            $totalKilograms += $line->kilograms;
        }

        $pricedLines = [];
        $subtotal = 0;
        $mrpTotal = 0;

        foreach ($lines as $line) {
            $tier = $line->overridePrice === null ? $line->product->tierFor($totalKilograms) : null;
            $tierPrice = $tier === null ? $line->overridePrice : $tier->price;

            $pricedLines[] = new PricedLine(
                productId: $line->product->id,
                kilograms: $line->kilograms,
                mrp: $line->product->mrp,
                tierPrice: $tierPrice,
                tier: $tier,
                overridden: $tier === null,
                lineTotal: $tierPrice * $line->kilograms,
            );

            $subtotal += $tierPrice * $line->kilograms;
            $mrpTotal += $line->product->mrp * $line->kilograms;
        }

        $band = $this->deliveryBandFor($totalKilograms);
        $deliveryCharge = $band->fee ?? 0;

        return new PricedCart(
            lines: $pricedLines,
            totalKilograms: $totalKilograms,
            subtotal: $subtotal,
            savings: $mrpTotal - $subtotal,
            deliveryCharge: $deliveryCharge,
            deliveryBand: $band,
            total: $subtotal + $deliveryCharge,
            averagePerKilogram: $totalKilograms === 0 ? null : intdiv(2 * $subtotal + $totalKilograms, 2 * $totalKilograms),
            nextPriceDrop: $this->nextPriceDrop($lines, $totalKilograms),
            kilogramsToFreeDelivery: $this->kilogramsToFreeDelivery($totalKilograms, $band),
        );
    }

    /**
     * The nearest weight at which a line already holding Kilograms gets
     * cheaper, with the saving at that weight across every line. An overridden
     * line keeps its price there, and a line holding nothing would save
     * nothing, so neither sets the weight.
     *
     * @param  list<CartLine>  $lines
     */
    private function nextPriceDrop(array $lines, int $totalKilograms): ?PriceDrop
    {
        $atKilograms = null;

        foreach ($lines as $line) {
            if ($line->overridePrice !== null || $line->kilograms === 0) {
                continue;
            }

            $drop = $line->product->nextDropAbove($totalKilograms);

            if ($drop !== null && ($atKilograms === null || $drop->min < $atKilograms)) {
                $atKilograms = $drop->min;
            }
        }

        if ($atKilograms === null) {
            return null;
        }

        $extraSaving = 0;

        foreach ($lines as $line) {
            if ($line->overridePrice === null) {
                $priceNow = $line->product->tierFor($totalKilograms)->price;
                $priceThen = $line->product->tierFor($atKilograms)->price;
                $extraSaving += ($priceNow - $priceThen) * $line->kilograms;
            }
        }

        return new PriceDrop($atKilograms, $atKilograms - $totalKilograms, $extraSaving);
    }

    /** Null for an empty Cart: no band starts below one kilogram. */
    private function deliveryBandFor(int $totalKilograms): ?DeliveryBand
    {
        if ($totalKilograms === 0) {
            return null;
        }

        foreach ($this->deliveryBands as $band) {
            if ($band->contains($totalKilograms)) {
                return $band;
            }
        }

        throw new LogicException("No Delivery Band covers {$totalKilograms} kg; the bands must cover every whole kilogram from one.");
    }

    private function kilogramsToFreeDelivery(int $totalKilograms, ?DeliveryBand $band): ?int
    {
        if ($band?->isFree()) {
            return null;
        }

        foreach ($this->deliveryBands as $candidate) {
            if ($candidate->isFree() && $candidate->min > $totalKilograms) {
                return $candidate->min - $totalKilograms;
            }
        }

        return null;
    }
}
