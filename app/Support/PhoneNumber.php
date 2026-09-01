<?php

namespace App\Support;

/**
 * Reduces a phone number as typed to the one canonical form the system stores.
 *
 * A Customer *is* their phone number, so this class is the thing that decides
 * whether two orders came from the same person. Storing `01712-345678` and
 * `+8801712345678` as different strings does not surface as a bug; it surfaces
 * as two customers, which is the one feature the customers table exists to
 * provide.
 *
 * The canonical form is E.164-shaped: `+` then country code then the national
 * number. Dialling rules are per-installation config, not constants, because
 * this is sold self-hosted.
 */
class PhoneNumber
{
    /**
     * The canonical form of a number as typed, or null when what was typed
     * cannot be one.
     */
    public static function normalise(string $number): ?string
    {
        $national = self::nationalDigits($number);

        if ($national === null) {
            return null;
        }

        return '+'.self::countryCode().$national;
    }

    public static function isValid(string $number): bool
    {
        return self::nationalDigits($number) !== null;
    }

    /**
     * Strip the number down to its national digits — no country code, no trunk
     * prefix, no punctuation — or null when the result is not a plausible
     * length.
     *
     * Order matters: the country code is removed before the trunk prefix, so
     * `+8801712345678` loses `880` and then keeps `1712345678`, while
     * `01712345678` has no country code to lose and drops its leading `0` to
     * reach the same digits.
     */
    private static function nationalDigits(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        $countryCode = self::countryCode();

        if ($countryCode !== '' && str_starts_with($digits, $countryCode)) {
            $digits = substr($digits, strlen($countryCode));
        }

        $trunkPrefix = (string) config('giopages.phone.trunk_prefix');

        if ($trunkPrefix !== '' && str_starts_with($digits, $trunkPrefix)) {
            $digits = substr($digits, strlen($trunkPrefix));
        }

        $length = strlen($digits);

        $withinBounds = $length >= (int) config('giopages.phone.national_length.min')
            && $length <= (int) config('giopages.phone.national_length.max');

        return $withinBounds ? $digits : null;
    }

    private static function countryCode(): string
    {
        return (string) config('giopages.phone.country_code');
    }
}
