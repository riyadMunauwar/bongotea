<?php

namespace App\Support;

use App\Support\Inventory\Grams;

/**
 * Formats a quantity of Kilograms for display.
 *
 * A bulk Order is a whole number of Kilograms, but Kilograms Sold counts a
 * retail Order's Packs by their weight (ADR-0034, ADR-0035), so a figure read
 * off the lines may fall between whole kilograms. It is read in grams and
 * turned into Kilograms here: a whole number stays an int, and anything else
 * keeps up to three decimals — so a store that sells no Packs reads exactly
 * as it always has. What this owns is that every screen says it the same way.
 */
class Kilograms
{
    /**
     * Render Kilograms with their unit, e.g. 1250 -> "1,250 kg", 12.5 -> "12.5 kg".
     */
    public static function format(int|float $kilograms): string
    {
        if (is_int($kilograms) || floor($kilograms) === $kilograms) {
            return number_format($kilograms).' kg';
        }

        return rtrim(rtrim(number_format($kilograms, 3), '0'), '.').' kg';
    }

    /**
     * Grams as Kilograms: an int where they make a whole number of them, a
     * float to the gram where they do not.
     */
    public static function fromGrams(int $grams): int|float
    {
        return $grams % Grams::PER_KILOGRAM === 0 ? intdiv($grams, Grams::PER_KILOGRAM) : $grams / Grams::PER_KILOGRAM;
    }

    /**
     * Several Kilogram figures added up, back to the gram, so a sum of parts
     * of a kilogram never drifts in its last decimal.
     *
     * @param  array<int|float>  $kilograms
     */
    public static function sum(array $kilograms): int|float
    {
        return self::fromGrams((int) round(array_sum($kilograms) * Grams::PER_KILOGRAM));
    }
}
