<?php

namespace App\Support\Settings;

use App\Support\Money;
use App\Support\Pricing\KilogramRanges;
use Illuminate\Support\Facades\Log;

/**
 * The Delivery Bands: the store-wide table every Cart's Delivery Charge is
 * read from, through `PriceBook::current()`. Tiers are not here: each Product
 * owns its own (ADR-0031), and a row saved before that still carrying a
 * `tiers` key is simply not read.
 *
 * The bands are an ordered list covering every whole kilogram from one without
 * gap or overlap, the last open-ended. The page refuses anything else, and so
 * does `with()` for a row that never came through the page, because the
 * pricing module assumes it. The defaults are the mockup's table, like the
 * other storefront groups' content defaults.
 */
class PricingSettings extends SettingsGroup
{
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
     * The bands normalised to whole numbers before `parent::with()` assigns
     * them, and a table that does not cover every kilogram once read as the
     * table this group already has. A row must never throw, and a gap here
     * would leave a Cart with no Delivery Charge.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
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

            $max = $band['max'] ?? null;

            $normalised[] = ['min' => (int) $band['min'], 'max' => $max === null || $max === '' ? null : (int) $max, 'fee' => $fee];
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
}
