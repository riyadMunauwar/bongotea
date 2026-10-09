<?php

namespace App\Support\Pricing;

/**
 * The one rule a Product's Tiers and the Delivery Bands both answer to: an
 * ordered list of `{min, max|null}` ranges covering every whole kilogram from
 * one upwards without gap or overlap, the last open-ended (ADR-0031). It is
 * what lets the pricing module assume every Cart weight has exactly one Tier
 * of each Product and one Delivery Charge.
 */
class KilogramRanges
{
    /**
     * What is wrong with the ranges, as a Merchant should read it, or null
     * when they cover every kilogram once. Values may arrive as a form types
     * them; a blank max is open-ended.
     *
     * @param  array<array-key, array<string, mixed>>  $ranges
     */
    public static function problem(array $ranges, string $noun = 'range'): ?string
    {
        $ranges = array_values($ranges);

        if ($ranges === []) {
            return "Add at least one {$noun}, starting at 1 kg and open-ended.";
        }

        $expectedMin = 1;
        $last = count($ranges) - 1;

        foreach ($ranges as $index => $range) {
            $position = $index + 1;
            $min = self::wholeNumber($range['min'] ?? null);
            $max = self::wholeNumber($range['max'] ?? null);

            if ($min !== $expectedMin) {
                return $index === 0
                    ? "The first {$noun} must start at 1 kg."
                    : "The {$noun} in row {$position} must start at {$expectedMin} kg, right after the one above it ends, so no weight is left out or counted twice.";
            }

            if ($max === null) {
                return $index === $last
                    ? null
                    : "Only the last {$noun} can be left open-ended; give the one in row {$position} an upper limit.";
            }

            if ($max < $min) {
                return "The {$noun} in row {$position} ends before it starts.";
            }

            $expectedMin = $max + 1;
        }

        return "Leave the last {$noun}'s upper limit blank, so every weight above it is covered.";
    }

    private static function wholeNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) === false ? -1 : (int) $value;
    }
}
