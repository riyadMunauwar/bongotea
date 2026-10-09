<?php

namespace App\Support\Storefront;

use App\Support\Money;

/**
 * Numbers as a Bangla reader reads them, for the one storefront page drawn
 * on the server without React — the Order Complete Page. The same rules as
 * `resources/js/lib/bangla.ts`: Bangla digits, Indian grouping, taka rounded
 * to the whole taka. Order numbers stay Latin and never come through here.
 */
final class Bangla
{
    private const DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** Every ASCII digit in the value as its Bangla digit; everything else as it was. */
    public static function digits(int|string $value): string
    {
        return strtr((string) $value, array_combine(array_map('strval', range(0, 9)), self::DIGITS));
    }

    /** A whole number in Bangla digits with Indian grouping: ১২,৩৪,৫৬৭. */
    public static function number(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $digits = (string) abs($value);

        /** The last three digits stand alone; every two before them are grouped. */
        if (strlen($digits) > 3) {
            $hundreds = substr($digits, -3);
            $pairs = strrev(implode(',', str_split(strrev(substr($digits, 0, -3)), 2)));
            $digits = $pairs.','.$hundreds;
        }

        return self::digits($sign.$digits);
    }

    /** Minor units as taka, rounded to the whole taka: ৳৬,৩৪৪. */
    public static function taka(int $minorUnits): string
    {
        return '৳'.self::number((int) round($minorUnits / Money::factor()));
    }
}
