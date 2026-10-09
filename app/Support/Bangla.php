<?php

namespace App\Support;

/**
 * Numbers as a Bangla reader reads them, for what the server writes in
 * Bangla — a text message — the way `resources/js/lib/bangla.ts` draws them
 * on the storefront: Bangla digits, Indian grouping, whole taka.
 */
class Bangla
{
    private const DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** Every ASCII digit in the value as its Bangla digit; everything else as it was. */
    public static function digits(string $value): string
    {
        return strtr($value, array_combine(range(0, 9), self::DIGITS));
    }

    /** Every Bangla digit in the value as its ASCII digit, since a phone keyboard may type either. */
    public static function latinDigits(string $value): string
    {
        return strtr($value, array_combine(self::DIGITS, array_map('strval', range(0, 9))));
    }

    /** A whole number in Bangla digits with Indian grouping: ১২,৩৪,৫৬৭. */
    public static function number(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $digits = (string) abs($value);

        if (strlen($digits) > 3) {
            $lastThree = substr($digits, -3);
            $rest = substr($digits, 0, -3);
            $digits = implode(',', str_split(strrev($rest), 2));
            $digits = strrev($digits).','.$lastThree;
        }

        return self::digits($sign.$digits);
    }

    /** Minor units as taka, rounded to the whole taka: ৳৬,৩৪৪. */
    public static function taka(int $minorUnits): string
    {
        return '৳'.self::number((int) round(Money::toMajor($minorUnits)));
    }
}
