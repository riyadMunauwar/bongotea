<?php

namespace App\Support\Pricing;

use LogicException;

/**
 * One kind's Delivery Zones (ADR-0039): every District in exactly one, the
 * "everywhere else" zone taking any District no other lists. The settings
 * group refuses to hold anything else, so a District always has a zone.
 */
final readonly class DeliveryZones
{
    /**
     * @param  list<DeliveryZone>  $zones  In the Merchant's order, exactly one of them "everywhere else".
     */
    public function __construct(
        public array $zones,
    ) {}

    /**
     * @param  list<array{name: string, districts: list<string>, everywhereElse: bool, bands: list<array{min: int, max: int|null, fee: int}>, freeDeliveryFrom?: int|null}>  $zones
     */
    public static function fromArray(array $zones): self
    {
        return new self(array_map(DeliveryZone::fromArray(...), $zones));
    }

    /** The zone a District is delivered under; null while no District is known. */
    public function for(?string $district): ?DeliveryZone
    {
        if ($district === null || $district === '') {
            return null;
        }

        $everywhereElse = null;

        foreach ($this->zones as $zone) {
            if ($zone->covers($district)) {
                return $zone;
            }

            if ($zone->isEverywhereElse) {
                $everywhereElse = $zone;
            }
        }

        return $everywhereElse ?? throw new LogicException('No Delivery Zone is "everywhere else", so a District nobody listed has none.');
    }

    /**
     * The least and the most a Cart of this weight and subtotal could pay
     * across every zone, for anywhere that shows the charge before a District
     * is known; null for an empty Cart.
     *
     * @return array{least: int, most: int}|null
     */
    public function rangeFor(int $kilograms, int $subtotal = 0): ?array
    {
        if ($kilograms === 0) {
            return null;
        }

        $charges = array_map(fn (DeliveryZone $zone): int => $zone->chargeFor($kilograms, $subtotal), $this->zones);

        return ['least' => min($charges), 'most' => max($charges)];
    }

    /**
     * What every zone answers alike, or null where they differ: a nudge
     * towards free delivery is only true before a District is known when it
     * is true wherever the parcel goes.
     *
     * @param  callable(DeliveryZone): ?int  $ask
     */
    public function agreed(callable $ask): ?int
    {
        $answers = array_unique(array_map(fn (DeliveryZone $zone): string => var_export($ask($zone), true), $this->zones));

        return count($answers) === 1 ? $ask($this->zones[0]) : null;
    }
}
