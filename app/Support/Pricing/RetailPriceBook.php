<?php

namespace App\Support\Pricing;

use App\Support\Inventory\Grams;
use App\Support\Settings\DeliveryChargeSettings;
use InvalidArgumentException;

/**
 * Retail pricing: the one authority on what a retail Cart costs (ADR-0034),
 * beside the bulk `PriceBook` and never inside it.
 *
 * Each line is its Pack Price times its count, with no Tier and no quantity
 * break. The Delivery Charge is read from the retail Delivery Zone the
 * District falls in (ADR-0039): the band its Packs' weight falls in, rounded
 * up to the next whole kilogram, waived once the subtotal reaches that zone's
 * threshold and never waived where it has none. Until a District is known
 * there is no charge, only the range it could be across every zone. The
 * storefront runs a TypeScript mirror of the same arithmetic
 * (`resources/js/lib/retail-pricing.ts`) held to it by the shared worked
 * examples in `tests/Fixtures/Pricing/retail-examples.json`.
 */
final readonly class RetailPriceBook
{
    public function __construct(
        public DeliveryZones $deliveryZones,
    ) {}

    /** The retail Delivery Zones as the Merchant has them in Settings. */
    public static function current(): self
    {
        return new self(app(DeliveryChargeSettings::class)->retailDeliveryZones());
    }

    /**
     * @param  list<RetailLine>  $lines
     * @param  string|null  $district  where the Order goes; null while nobody has said
     */
    public function price(array $lines, ?string $district = null): PricedRetailCart
    {
        $pricedLines = [];
        $packCount = 0;
        $grams = 0;
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
            $grams += Grams::ofPackets($line->count, $line->pack->grams);
            $subtotal += $packPrice * $line->count;
            $savings += $lineSavings;
        }

        $kilograms = Grams::deliveryKilograms($grams);

        $zone = $this->deliveryZones->for($district);
        $deliveryCharge = $zone?->chargeFor($kilograms, $subtotal) ?? 0;

        return new PricedRetailCart(
            lines: $pricedLines,
            packCount: $packCount,
            kilograms: $kilograms,
            subtotal: $subtotal,
            savings: $savings,
            deliveryCharge: $deliveryCharge,
            deliveryZone: $zone,
            deliveryBand: $zone?->bandFor($kilograms),
            deliveryRange: $this->deliveryZones->rangeFor($kilograms, $subtotal),
            total: $subtotal + $deliveryCharge,
            amountToFreeDelivery: $zone === null
                ? $this->deliveryZones->agreed(fn (DeliveryZone $candidate): ?int => $candidate->amountToFreeDelivery($subtotal))
                : $zone->amountToFreeDelivery($subtotal),
        );
    }
}
