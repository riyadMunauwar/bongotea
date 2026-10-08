<?php

namespace App\Support\Pricing;

use App\Support\Money;
use App\Support\Settings\PricingSettings;
use InvalidArgumentException;
use LogicException;

/**
 * The pricing module: the one authority on what a Cart costs (ADR-0028).
 *
 * The Tier is chosen by the total Kilograms across every line, and every line
 * is priced from that same Tier; the Delivery Charge is read from its own
 * table by the same total. The Checkout's Capture, Repricing and an Order
 * taken by phone all ask this, and the storefront runs a TypeScript mirror of
 * the same arithmetic (`resources/js/lib/pricing.ts`) held to it by the shared
 * worked examples in `tests/Fixtures/Pricing/examples.json`.
 *
 * Both tables are assumed to cover every whole kilogram from one without gap
 * or overlap, which the Pricing settings refuse to save otherwise.
 */
final readonly class PriceBook
{
    /**
     * @param  list<Tier>  $tiers  In order, the first starting at one, the last open-ended.
     * @param  list<DeliveryBand>  $deliveryBands  In order, the first starting at one, the last open-ended.
     * @param  int  $minorUnitsPerTaka  What a percentage discount rounds to.
     */
    public function __construct(
        public array $tiers,
        public array $deliveryBands,
        public int $minorUnitsPerTaka,
    ) {}

    /** The Tiers and Delivery Bands as the Merchant has them in Settings. */
    public static function current(): self
    {
        $settings = app(PricingSettings::class);

        return new self(
            tiers: array_map(fn (array $tier): Tier => new Tier($tier['key'], $tier['min'], $tier['max']), $settings->tiers),
            deliveryBands: array_map(fn (array $band): DeliveryBand => new DeliveryBand($band['min'], $band['max'], $band['fee']), $settings->deliveryBands),
            minorUnitsPerTaka: Money::factor(),
        );
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

            $totalKilograms += $line->kilograms;
        }

        $tierIndex = $this->tierIndexFor($totalKilograms);
        $tier = $this->tiers[$tierIndex];
        $nextTier = $this->tiers[$tierIndex + 1] ?? null;

        $pricedLines = [];
        $subtotal = 0;
        $regularTotal = 0;
        $extraSaving = 0;

        foreach ($lines as $line) {
            $ladder = $this->ladder($line->product);
            $inTier = $ladder[$tierIndex];
            $overridden = $line->overridePrice !== null;
            $tierPrice = $overridden ? $line->overridePrice : $inTier->price;

            $nextTierPrice = match (true) {
                $nextTier === null => null,
                $overridden => $tierPrice,
                default => $ladder[$tierIndex + 1]->price,
            };

            $pricedLines[] = new PricedLine(
                productId: $line->product->id,
                kilograms: $line->kilograms,
                regularPrice: $line->product->regularPrice,
                tierPrice: $tierPrice,
                discount: $overridden ? null : $inTier->discount,
                overridden: $overridden,
                lineTotal: $tierPrice * $line->kilograms,
                nextTierPrice: $nextTierPrice,
            );

            $subtotal += $tierPrice * $line->kilograms;
            $regularTotal += $line->product->regularPrice * $line->kilograms;
            $extraSaving += $nextTierPrice === null ? 0 : ($tierPrice - $nextTierPrice) * $line->kilograms;
        }

        $band = $this->deliveryBandFor($totalKilograms);
        $deliveryCharge = $band->fee ?? 0;

        return new PricedCart(
            tier: $tier,
            lines: $pricedLines,
            totalKilograms: $totalKilograms,
            subtotal: $subtotal,
            savings: $regularTotal - $subtotal,
            deliveryCharge: $deliveryCharge,
            deliveryBand: $band,
            total: $subtotal + $deliveryCharge,
            averagePerKilogram: $totalKilograms === 0 ? null : intdiv(2 * $subtotal + $totalKilograms, 2 * $totalKilograms),
            nextTier: $nextTier === null ? null : new NextTier($nextTier, $nextTier->min - $totalKilograms, $extraSaving),
            kilogramsToFreeDelivery: $this->kilogramsToFreeDelivery($totalKilograms, $band),
        );
    }

    /**
     * A Product's price in every Tier, in Tier order: the first Tier is always
     * the Regular Price, and a Tier the Product names no Discount for charges
     * what the Tier below it charges.
     *
     * @return list<TierPrice>
     */
    public function ladder(ProductPrices $product): array
    {
        $ladder = [];
        $price = $product->regularPrice;
        $discount = null;

        foreach ($this->tiers as $index => $tier) {
            $named = $index === 0 ? null : $product->discountFor($tier);

            if ($named !== null) {
                $price = $named->applyTo($product->regularPrice, $this->minorUnitsPerTaka);
                $discount = $named->value === 0 ? null : $named;
            }

            $ladder[] = new TierPrice($tier, $price, $discount);
        }

        return $ladder;
    }

    /** An empty Cart sits in the first Tier, so it shows the Regular Prices. */
    private function tierIndexFor(int $totalKilograms): int
    {
        if ($totalKilograms === 0) {
            return 0;
        }

        foreach ($this->tiers as $index => $tier) {
            if ($tier->contains($totalKilograms)) {
                return $index;
            }
        }

        throw new LogicException("No Tier covers {$totalKilograms} kg; the Tiers must cover every whole kilogram from one.");
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
