<?php

namespace App\Support\Pricing;

use LogicException;

/**
 * A Delivery Zone (ADR-0039): a named group of Districts and the Delivery
 * Bands a parcel to any of them is charged from. One zone of each kind is
 * "everywhere else", holding no Districts of its own, so a District nobody
 * listed still has a zone. A retail zone may also say the subtotal from which
 * its delivery is free; a bulk zone never does.
 */
final readonly class DeliveryZone
{
    /**
     * @param  list<string>  $districts  As the store stores them; empty for the "everywhere else" zone.
     * @param  list<DeliveryBand>  $bands  In order, the first starting at one, the last open-ended.
     */
    public function __construct(
        public string $name,
        public array $districts,
        public bool $isEverywhereElse,
        public array $bands,

        /** The subtotal at which delivery is free, in minor units; null for never. */
        public ?int $freeDeliveryFrom = null,
    ) {}

    /**
     * A zone as the settings group stores it.
     *
     * @param  array{name: string, districts: list<string>, everywhereElse: bool, bands: list<array{min: int, max: int|null, fee: int}>, freeDeliveryFrom?: int|null}  $zone
     */
    public static function fromArray(array $zone): self
    {
        return new self(
            name: $zone['name'],
            districts: $zone['districts'],
            isEverywhereElse: $zone['everywhereElse'],
            bands: array_map(fn (array $band): DeliveryBand => new DeliveryBand($band['min'], $band['max'], $band['fee']), $zone['bands']),
            freeDeliveryFrom: $zone['freeDeliveryFrom'] ?? null,
        );
    }

    public function covers(string $district): bool
    {
        return in_array($district, $this->districts, true);
    }

    /** Null for an empty Cart: no band starts below one kilogram. */
    public function bandFor(int $kilograms): ?DeliveryBand
    {
        if ($kilograms === 0) {
            return null;
        }

        foreach ($this->bands as $band) {
            if ($band->contains($kilograms)) {
                return $band;
            }
        }

        throw new LogicException("No Delivery Band of {$this->name} covers {$kilograms} kg; a zone's bands must cover every whole kilogram from one.");
    }

    /** Whether a subtotal this size is delivered free here. */
    public function isFreeFor(int $subtotal): bool
    {
        return $this->freeDeliveryFrom !== null && $subtotal >= $this->freeDeliveryFrom;
    }

    /** What a Cart of this weight and subtotal pays here; nothing for an empty one. */
    public function chargeFor(int $kilograms, int $subtotal = 0): int
    {
        $band = $this->bandFor($kilograms);

        return $band === null || $this->isFreeFor($subtotal) ? 0 : $band->fee;
    }

    /** How many more Kilograms reach a free band here; null where delivery is free already or no free band lies ahead. */
    public function kilogramsToFreeDelivery(int $kilograms): ?int
    {
        if ($this->bandFor($kilograms)?->isFree()) {
            return null;
        }

        foreach ($this->bands as $band) {
            if ($band->isFree() && $band->min > $kilograms) {
                return $band->min - $kilograms;
            }
        }

        return null;
    }

    /** What the subtotal still lacks for free delivery here; null once it is free, or where it never is. */
    public function amountToFreeDelivery(int $subtotal): ?int
    {
        return $this->freeDeliveryFrom === null || $this->isFreeFor($subtotal) ? null : $this->freeDeliveryFrom - $subtotal;
    }

    /** What an Order charged here records as its delivery: the zone and the band, "Inside Dhaka · 6–10 kg". */
    public function labelFor(?DeliveryBand $band): string
    {
        return $band === null ? $this->name : "{$this->name} · {$band->label()}";
    }

    /**
     * @return array{name: string, districts: list<string>, everywhereElse: bool, bands: list<array{min: int, max: int|null, fee: int}>, freeDeliveryFrom: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'districts' => $this->districts,
            'everywhereElse' => $this->isEverywhereElse,
            'bands' => array_map(fn (DeliveryBand $band): array => $band->toArray(), $this->bands),
            'freeDeliveryFrom' => $this->freeDeliveryFrom,
        ];
    }
}
