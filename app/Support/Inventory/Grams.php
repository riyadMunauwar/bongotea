<?php

namespace App\Support\Inventory;

/**
 * The stock ledger's unit, and the one place it is converted.
 *
 * Every ledger quantity — a Stock Movement, a Supply line, a Stock Count — is
 * a whole number of grams (ADR-0035), because a Pack weighed out of loose
 * stock leaves a shelf holding something that is no longer a whole number of
 * kilograms. The Merchant still types and reads kilograms: a form converts on
 * the way in with `fromKilograms()`, and a screen draws with `toKilograms()`
 * or `format()`, showing decimals only where a shelf actually holds a part of
 * a kilogram.
 *
 * Cost is the other half. It stays what it always was — minor units per
 * **kilogram** — because a price per gram is a fraction of a poisha that an
 * integer column would truncate, and a weighted average carried that way
 * would drift. A row's money is therefore its grams × its cost per kilogram ÷
 * 1000, rounded half away from zero. The ledger's own money (Value at Cost) is
 * divided once, over the sum of its rows: `costOfSum()` for a sum read back
 * from SQL, `SUMMED_COST` where the sum is made in the query. A Supply is an
 * invoice, so each of its lines is rounded and the lines are added up, the way
 * the document in the Merchant's hand adds up: `cost()` for one line,
 * `LINE_COST` for the same figure in a query. A weighted average needs
 * no conversion at all — Σ(grams × cost per kg) ÷ Σ(grams) is a cost per
 * kilogram already.
 */
class Grams
{
    public const PER_KILOGRAM = 1000;

    /**
     * The money a set of ledger rows carries, summed and rounded in the query:
     * Σ(quantity × unit_cost) ÷ 1000, rounded half away from zero the way PHP's
     * `round()` is, and null where nothing was summed. Both SQLite and
     * PostgreSQL round a decimal half away from zero.
     */
    public const SUMMED_COST = 'cast(round(sum(quantity * unit_cost) / 1000.0) as bigint)';

    /**
     * One row's money in a query, rounded the way `cost()` rounds it, for
     * summing a Supply's lines as an invoice adds them up.
     */
    public const LINE_COST = 'cast(round(quantity * unit_cost / 1000.0) as bigint)';

    /**
     * A kilogram figure a Merchant typed, as the grams the ledger records:
     * "2.5" -> 2500. Rounded rather than truncated, so 0.1 + 0.2 kg does not
     * quietly lose a gram to floating point.
     */
    public static function fromKilograms(int|float|string $kilograms): int
    {
        return (int) round(((float) $kilograms) * self::PER_KILOGRAM);
    }

    /**
     * Grams as a kilogram figure for a screen, without a unit, with up to
     * three decimals and only as many as it needs: 12000 -> "12",
     * 2500 -> "2.5", 1250250 -> "1,250.25".
     */
    public static function toKilograms(int $grams): string
    {
        $kilograms = number_format($grams / self::PER_KILOGRAM, 3);

        return str_contains($kilograms, '.') ? rtrim(rtrim($kilograms, '0'), '.') : $kilograms;
    }

    /**
     * Grams as kilograms with the unit, the way `Kilograms::format()` says a
     * whole number of them: 2500 -> "2.5 kg".
     */
    public static function format(int $grams): string
    {
        return self::toKilograms($grams).' kg';
    }

    /**
     * What these grams are worth at a cost per kilogram, in minor units.
     */
    public static function cost(int $grams, int $costPerKilogram): int
    {
        return (int) round($grams * $costPerKilogram / self::PER_KILOGRAM);
    }

    /**
     * A Σ(grams × cost per kg) read back from the database as the money it
     * comes to, in minor units, or null where nothing was summed.
     */
    public static function costOfSum(mixed $summed): ?int
    {
        return $summed === null ? null : (int) round(((float) $summed) / self::PER_KILOGRAM);
    }
}
