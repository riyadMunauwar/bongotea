<?php

namespace App\Support\Settings;

use App\Support\Money;
use App\Support\Pricing\DeliveryZoneRules;
use App\Support\Pricing\DeliveryZones;
use Illuminate\Support\Facades\Log;

/**
 * The Delivery Zones (ADR-0039): bulk and retail each a list of their own,
 * read through `PriceBook::current()` and `RetailPriceBook::current()`, shown
 * to the Merchant as Bulk Delivery Charges and Retail Delivery Charges. Tiers
 * are not here: each Product owns its own (ADR-0031).
 *
 * Each zone is a name, the Districts it covers — none for the one zone of a
 * list that is "everywhere else" — and its Delivery Bands, an ordered list
 * covering every whole kilogram from one without gap or overlap, the last
 * open-ended; a retail zone also holds the subtotal from which its delivery is
 * free, null for never. The page refuses anything else, and so does `with()`
 * for a row that never came through the page, because the pricing module
 * assumes it.
 *
 * The defaults are what a fresh installation opens with. A store that was
 * charging before zones existed had its table and flat retail charge carried
 * over as one "everywhere else" zone each, by migration, so nothing it charged
 * moved.
 */
class DeliveryChargeSettings extends SettingsGroup
{
    /**
     * Fees in minor units.
     *
     * @var list<array{name: string, districts: list<string>, everywhereElse: bool, bands: list<array{min: int, max: int|null, fee: int}>}>
     */
    public array $bulkZones;

    /**
     * Fees and thresholds in minor units.
     *
     * @var list<array{name: string, districts: list<string>, everywhereElse: bool, bands: list<array{min: int, max: int|null, fee: int}>, freeDeliveryFrom: int|null}>
     */
    public array $retailZones;

    public function __construct()
    {
        $bulkBands = [
            ['min' => 1, 'max' => 5, 'fee' => Money::toMinorUnits(120)],
            ['min' => 6, 'max' => 10, 'fee' => Money::toMinorUnits(150)],
            ['min' => 11, 'max' => 20, 'fee' => Money::toMinorUnits(200)],
            ['min' => 21, 'max' => 50, 'fee' => Money::toMinorUnits(250)],
            ['min' => 51, 'max' => null, 'fee' => 0],
        ];

        $this->bulkZones = [
            ['name' => 'Inside Dhaka', 'districts' => ['Dhaka'], 'everywhereElse' => false, 'bands' => $bulkBands],
            ['name' => 'Outside Dhaka', 'districts' => [], 'everywhereElse' => true, 'bands' => $bulkBands],
        ];

        $this->retailZones = [
            ['name' => 'Inside Dhaka', 'districts' => ['Dhaka'], 'everywhereElse' => false, 'bands' => [['min' => 1, 'max' => null, 'fee' => Money::toMinorUnits(80)]], 'freeDeliveryFrom' => Money::toMinorUnits(1000)],
            ['name' => 'Outside Dhaka', 'districts' => [], 'everywhereElse' => true, 'bands' => [['min' => 1, 'max' => null, 'fee' => Money::toMinorUnits(130)]], 'freeDeliveryFrom' => Money::toMinorUnits(1000)],
        ];
    }

    /**
     * Still `pricing`, the name this group had while it also held the Tiers:
     * the row is found by this key, so changing it would read every Merchant's
     * saved zones as never saved.
     */
    public static function key(): string
    {
        return 'pricing';
    }

    public function bulkDeliveryZones(): DeliveryZones
    {
        return DeliveryZones::fromArray($this->bulkZones);
    }

    public function retailDeliveryZones(): DeliveryZones
    {
        return DeliveryZones::fromArray($this->retailZones);
    }

    /**
     * The zones normalised to their stored shape before `parent::with()`
     * assigns them, and a list that breaks the rules read as the list this
     * group already has. A row must never throw, and a District with no zone
     * or a weight with no band would leave a Cart with no Delivery Charge.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        foreach (['bulkZones' => false, 'retailZones' => true] as $property => $hasThreshold) {
            if (! array_key_exists($property, $values)) {
                continue;
            }

            $zones = self::normaliseZones($values[$property], $hasThreshold);

            if ($zones === null) {
                Log::warning('A stored list of Delivery Zones breaks their rules and is read as the list already in force.', ['property' => $property]);
                unset($values[$property]);
            } else {
                $values[$property] = $zones;
            }
        }

        return parent::with($values);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function normaliseZones(mixed $zones, bool $hasThreshold): ?array
    {
        if (! is_array($zones) || DeliveryZoneRules::problem($zones, config('giopages.districts')) !== null) {
            return null;
        }

        $normalised = [];

        foreach (array_values($zones) as $zone) {
            $bands = [];

            foreach (array_values($zone['bands']) as $band) {
                $fee = filter_var($band['fee'] ?? null, FILTER_VALIDATE_INT);

                if ($fee === false || $fee < 0) {
                    return null;
                }

                $max = $band['max'] ?? null;
                $bands[] = ['min' => (int) $band['min'], 'max' => $max === null || $max === '' ? null : (int) $max, 'fee' => $fee];
            }

            $everywhereElse = DeliveryZoneRules::isEverywhereElse($zone);

            $row = [
                'name' => trim((string) $zone['name']),
                'districts' => $everywhereElse ? [] : array_values(array_map('strval', $zone['districts'])),
                'everywhereElse' => $everywhereElse,
                'bands' => $bands,
            ];

            if ($hasThreshold) {
                $from = $zone['freeDeliveryFrom'] ?? null;

                if ($from !== null && $from !== '' && (filter_var($from, FILTER_VALIDATE_INT) === false || (int) $from < 0)) {
                    return null;
                }

                $row['freeDeliveryFrom'] = $from === null || $from === '' ? null : (int) $from;
            }

            $normalised[] = $row;
        }

        return $normalised;
    }
}
