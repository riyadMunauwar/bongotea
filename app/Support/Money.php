<?php

namespace App\Support;

use App\Support\Settings\GeneralSettings;

/**
 * Formats integer minor units for display.
 *
 * Money is stored and computed as minor units and converted only at a
 * boundary — the admin form and the rendered page. Nothing outside this class
 * knows the currency symbol or how many decimal places it has; both are
 * General Settings, so a Merchant changes them from the admin.
 */
class Money
{
    /**
     * Render minor units as the installation's currency, e.g. 99000 -> "৳990.00".
     */
    public static function format(int $minorUnits): string
    {
        return app(GeneralSettings::class)->currencySymbol.self::toMajorString($minorUnits);
    }

    /**
     * Minor units as a decimal string, for a form field the merchant types into.
     */
    public static function toMajorString(int $minorUnits): string
    {
        return number_format($minorUnits / self::factor(), self::decimals(), '.', '');
    }

    /**
     * Minor units as a whole number of major units, for a field that takes
     * whole taka: 62000 -> "620". An amount that is not a whole unit keeps
     * its decimals, so the field refuses it rather than a save quietly
     * dropping them.
     */
    public static function toWholeMajorString(int $minorUnits): string
    {
        return $minorUnits % self::factor() === 0
            ? (string) intdiv($minorUnits, self::factor())
            : self::toMajorString($minorUnits);
    }

    /**
     * Minor units as a number in major units, for a chart axis that draws
     * its own symbol and decimals. Display goes through `format()`.
     */
    public static function toMajor(int $minorUnits): float
    {
        return round($minorUnits / self::factor(), self::decimals());
    }

    /**
     * A merchant-typed decimal back to minor units, rounded rather than
     * truncated so 9.995 does not silently become 9.99.
     */
    public static function toMinorUnits(int|float|string $majorUnits): int
    {
        return (int) round(((float) $majorUnits) * self::factor());
    }

    private static function decimals(): int
    {
        return app(GeneralSettings::class)->currencyDecimals;
    }

    /**
     * How many minor units make one whole unit of the currency: what a Tier
     * Price rounds to.
     */
    public static function factor(): int
    {
        return 10 ** self::decimals();
    }
}
