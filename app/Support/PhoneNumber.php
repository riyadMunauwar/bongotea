<?php

namespace App\Support;

use App\Support\Settings\GeneralSettings;

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
 * number. Dialling rules are General Settings, not constants, because this is
 * sold self-hosted and a Merchant sets their country from the admin.
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

        return '+'.self::settings()->phoneCountryCode.$national;
    }

    public static function isValid(string $number): bool
    {
        return self::nationalDigits($number) !== null;
    }

    /**
     * A stored number as it is dialled at home — the trunk prefix and the
     * national digits, no country code and no punctuation — or null when what
     * was stored cannot be one.
     *
     * Every Courier here is a domestic one and takes a domestic number:
     * Steadfast documents `recipient_phone` as eleven digits and refuses
     * `+8801712345678`. The rule for turning one form into the other is the
     * dialling rules, which live here, so no driver has to know them.
     */
    public static function asDialled(string $number): ?string
    {
        $national = self::nationalDigits($number);

        return $national === null ? null : self::settings()->phoneTrunkPrefix.$national;
    }

    /**
     * A number as an SMS Gateway takes it — the country code and the
     * national digits, no `+` and no punctuation, so `8801712345678` — or
     * null when what was given cannot be one.
     */
    public static function asInternational(string $number): ?string
    {
        $national = self::nationalDigits($number);

        return $national === null ? null : self::settings()->phoneCountryCode.$national;
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

        $settings = self::settings();

        $countryCode = $settings->phoneCountryCode;

        if ($countryCode !== '' && str_starts_with($digits, $countryCode)) {
            $digits = substr($digits, strlen($countryCode));
        }

        $trunkPrefix = $settings->phoneTrunkPrefix;

        if ($trunkPrefix !== '' && str_starts_with($digits, $trunkPrefix)) {
            $digits = substr($digits, strlen($trunkPrefix));
        }

        $length = strlen($digits);

        $withinBounds = $length >= $settings->phoneNationalLengthMin
            && $length <= $settings->phoneNationalLengthMax;

        return $withinBounds ? $digits : null;
    }

    private static function settings(): GeneralSettings
    {
        return app(GeneralSettings::class);
    }
}
