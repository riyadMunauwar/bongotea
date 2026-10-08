<?php

namespace App\Support\Settings;

use App\Support\Money;
use App\Support\Pricing\KilogramRanges;
use Illuminate\Support\Facades\Log;

/**
 * The Tiers and the Delivery Bands: the two store-wide tables every Cart is
 * priced from (ADR-0028), read through `PriceBook::current()`.
 *
 * Both are ordered lists covering every whole kilogram from one without gap
 * or overlap, the last open-ended. The page refuses anything else, and so does
 * `with()` for a row that never came through the page, because the pricing
 * module assumes it. The defaults are the mockup's tables, like the other
 * storefront groups' content defaults.
 */
class PricingSettings extends SettingsGroup
{
    /**
     * A Tier's key is what a Product's Tier Discounts are stored against, so
     * moving a Tier's edges keeps every Discount on it and a new Tier starts
     * with none.
     *
     * @var list<array{key: string, min: int, max: int|null}>
     */
    public array $tiers = [
        ['key' => 'tier-1', 'min' => 1, 'max' => 5],
        ['key' => 'tier-2', 'min' => 6, 'max' => 10],
        ['key' => 'tier-3', 'min' => 11, 'max' => 15],
        ['key' => 'tier-4', 'min' => 16, 'max' => 20],
        ['key' => 'tier-5', 'min' => 21, 'max' => 50],
        ['key' => 'tier-6', 'min' => 51, 'max' => null],
    ];

    /**
     * Fees in minor units.
     *
     * @var list<array{min: int, max: int|null, fee: int}>
     */
    public array $deliveryBands;

    public function __construct()
    {
        $this->deliveryBands = [
            ['min' => 1, 'max' => 5, 'fee' => Money::toMinorUnits(120)],
            ['min' => 6, 'max' => 10, 'fee' => Money::toMinorUnits(150)],
            ['min' => 11, 'max' => 20, 'fee' => Money::toMinorUnits(200)],
            ['min' => 21, 'max' => 50, 'fee' => Money::toMinorUnits(250)],
            ['min' => 51, 'max' => null, 'fee' => 0],
        ];
    }

    public static function key(): string
    {
        return 'pricing';
    }

    /**
     * Each table normalised to whole numbers before `parent::with()` assigns
     * it, and a table that does not cover every kilogram once — or a Tier
     * without a key, or two sharing one — read as the table this group
     * already has. A row must never throw, and a gap here would leave a Cart
     * with no price.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        if (array_key_exists('tiers', $values)) {
            $tiers = self::normaliseTiers($values['tiers']);

            if ($tiers === null) {
                self::warnKept('tiers');
                unset($values['tiers']);
            } else {
                $values['tiers'] = $tiers;
            }
        }

        if (array_key_exists('deliveryBands', $values)) {
            $bands = self::normaliseBands($values['deliveryBands']);

            if ($bands === null) {
                self::warnKept('deliveryBands');
                unset($values['deliveryBands']);
            } else {
                $values['deliveryBands'] = $bands;
            }
        }

        return parent::with($values);
    }

    /**
     * Said out loud, because a table read as the one already in force
     * otherwise looks like a Merchant who never saved one.
     */
    private static function warnKept(string $property): void
    {
        Log::warning('A stored pricing table does not cover every kilogram once and is read as the table already in force.', ['property' => $property]);
    }

    /**
     * @return list<array{key: string, min: int, max: int|null}>|null
     */
    private static function normaliseTiers(mixed $tiers): ?array
    {
        if (! is_array($tiers) || KilogramRanges::problem(self::rows($tiers)) !== null) {
            return null;
        }

        $normalised = [];

        foreach (self::rows($tiers) as $tier) {
            $key = $tier['key'] ?? null;

            if (! is_string($key) || $key === '') {
                return null;
            }

            $normalised[] = ['key' => $key, ...self::range($tier)];
        }

        $keys = array_column($normalised, 'key');

        return count(array_unique($keys)) === count($keys) ? $normalised : null;
    }

    /**
     * @return list<array{min: int, max: int|null, fee: int}>|null
     */
    private static function normaliseBands(mixed $bands): ?array
    {
        if (! is_array($bands) || KilogramRanges::problem(self::rows($bands)) !== null) {
            return null;
        }

        $normalised = [];

        foreach (self::rows($bands) as $band) {
            $fee = filter_var($band['fee'] ?? null, FILTER_VALIDATE_INT);

            if ($fee === false || $fee < 0) {
                return null;
            }

            $normalised[] = [...self::range($band), 'fee' => $fee];
        }

        return $normalised;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        return array_values(array_map(fn (mixed $row): array => is_array($row) ? $row : [], $rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{min: int, max: int|null}
     */
    private static function range(array $row): array
    {
        $max = $row['max'] ?? null;

        return ['min' => (int) $row['min'], 'max' => $max === null || $max === '' ? null : (int) $max];
    }
}
