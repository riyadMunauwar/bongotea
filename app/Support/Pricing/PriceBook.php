<?php

namespace App\Support\Pricing;

use App\Support\Settings\DeliveryChargeSettings;
use InvalidArgumentException;

/**
 * The pricing module: the one authority on what a Cart costs (ADR-0031).
 *
 * Each Product owns its Tiers, and every line is priced from the Tier of its
 * own Product that the Cart's total Kilograms — across every line — fall in;
 * the Delivery Charge is read by the same total from the Delivery Bands of the
 * bulk Delivery Zone the District falls in (ADR-0039). The
 * Checkout's Capture, Repricing and an Order taken by phone all ask this, and
 * the storefront runs a TypeScript mirror of the same arithmetic
 * (`resources/js/lib/pricing.ts`) held to it by the shared worked examples in
 * `tests/Fixtures/Pricing/examples.json`.
 *
 * A Product's Tiers and each zone's Delivery Bands are all assumed to cover
 * every whole kilogram from one without gap or overlap, which the Product and
 * the Delivery Charges settings refuse to save otherwise.
 *
 * Until a District is known no zone is, so the Cart is priced with no
 * Delivery Charge and the range it could be across every zone, which is what
 * the storefront shows before Checkout.
 */
final readonly class PriceBook
{
    public function __construct(
        public DeliveryZones $deliveryZones,
    ) {}

    /** The bulk Delivery Zones as the Merchant has them in Settings. */
    public static function current(): self
    {
        return new self(app(DeliveryChargeSettings::class)->bulkDeliveryZones());
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  string|null  $district  where the Order goes; null while nobody has said
     */
    public function price(array $lines, ?string $district = null): PricedCart
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

        $zone = $this->deliveryZones->for($district);
        $band = $zone?->bandFor($totalKilograms);
        $deliveryCharge = $band->fee ?? 0;

        return new PricedCart(
            lines: $pricedLines,
            totalKilograms: $totalKilograms,
            subtotal: $subtotal,
            savings: $mrpTotal - $subtotal,
            deliveryCharge: $deliveryCharge,
            deliveryZone: $zone,
            deliveryBand: $band,
            deliveryRange: $this->deliveryZones->rangeFor($totalKilograms),
            total: $subtotal + $deliveryCharge,
            averagePerKilogram: $totalKilograms === 0 ? null : intdiv(2 * $subtotal + $totalKilograms, 2 * $totalKilograms),
            nextPriceDrop: $this->nextPriceDrop($lines, $totalKilograms),
            kilogramsToFreeDelivery: $zone === null
                ? $this->deliveryZones->agreed(fn (DeliveryZone $candidate): ?int => $candidate->kilogramsToFreeDelivery($totalKilograms))
                : $zone->kilogramsToFreeDelivery($totalKilograms),
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
}
