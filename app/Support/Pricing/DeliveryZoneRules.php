<?php

namespace App\Support\Pricing;

/**
 * The rule one kind's Delivery Zones answer to (ADR-0039): named, each name
 * once; exactly one of them "everywhere else"; every other zone listing at
 * least one District the store knows, and no District listed twice; and each
 * zone's bands covering every whole kilogram once (`KilogramRanges`). It is
 * what lets the pricing module assume every District has exactly one zone
 * and every weight exactly one Delivery Charge in it.
 */
class DeliveryZoneRules
{
    /**
     * What is wrong with the zones, as a Merchant should read it, or null
     * when they hold. Values may arrive as a form types them.
     *
     * @param  array<array-key, mixed>  $zones
     * @param  list<string>  $knownDistricts
     */
    public static function problem(array $zones, array $knownDistricts): ?string
    {
        $zones = array_values(array_map(fn (mixed $zone): array => is_array($zone) ? $zone : [], $zones));

        if ($zones === []) {
            return 'Add at least one zone, and mark one of them as everywhere else.';
        }

        $names = [];
        $listedIn = [];
        $everywhereElse = 0;

        foreach ($zones as $index => $zone) {
            $name = trim((string) ($zone['name'] ?? ''));
            $label = $name === '' ? 'The zone in row '.($index + 1) : $name;

            if ($name === '') {
                return "{$label} needs a name.";
            }

            if (isset($names[mb_strtolower($name)])) {
                return "Two zones are called {$name}; give each a name of its own.";
            }

            $names[mb_strtolower($name)] = true;

            if (self::isEverywhereElse($zone)) {
                $everywhereElse++;
            } else {
                $districts = is_array($zone['districts'] ?? null) ? array_values($zone['districts']) : [];

                if ($districts === []) {
                    return "{$name} lists no District; list the Districts it covers, or mark it as everywhere else.";
                }

                foreach ($districts as $district) {
                    if (! in_array($district, $knownDistricts, true)) {
                        return "{$name} lists a District the store does not know.";
                    }

                    if (isset($listedIn[$district])) {
                        return "{$district} is in both {$listedIn[$district]} and {$name}; a District belongs to one zone.";
                    }

                    $listedIn[$district] = $name;
                }
            }

            $bands = is_array($zone['bands'] ?? null) ? $zone['bands'] : [];
            $bandProblem = KilogramRanges::problem(array_map(fn (mixed $band): array => is_array($band) ? $band : [], $bands));

            if ($bandProblem !== null) {
                return "{$name}: {$bandProblem}";
            }
        }

        return match ($everywhereElse) {
            1 => null,
            0 => 'Mark one zone as everywhere else, so a District no zone lists still has a Delivery Charge.',
            default => 'Only one zone can be everywhere else.',
        };
    }

    /** @param  array<array-key, mixed>  $zone */
    public static function isEverywhereElse(array $zone): bool
    {
        return filter_var($zone['everywhereElse'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
