<?php

namespace App\Support\Inventory;

use Illuminate\Database\Connection;
use InvalidArgumentException;

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
 * from SQL, `summedCost()` where the sum is made in the query. A Supply is an
 * invoice, so each of its lines is rounded and the lines are added up, the way
 * the document in the Merchant's hand adds up: `cost()` for one line,
 * `lineCost()` for the same figure in a query. A weighted average needs
 * no conversion at all — Σ(grams × cost per kg) ÷ Σ(grams) is a cost per
 * kilogram already.
 *
 * Packing is the third conversion: grams into a Pack's packets and back, and the
 * whole kilograms a Pack can make, for a refusal to name. It is mirrored for
 * the storefront by `resources/js/lib/grams.ts`, and the two are held to the
 * same arithmetic by `tests/Fixtures/Inventory/packing-examples.json`. No
 * other place converts a weight to packets.
 */
class Grams
{
    public const PER_KILOGRAM = 1000;

    /**
     * The money a set of ledger rows carries, summed and rounded in the query:
     * Σ(quantity × unit_cost) ÷ 1000, rounded half away from zero the way PHP's
     * `round()` is, and null where nothing was summed. SQLite, PostgreSQL and
     * MySQL all round a decimal half away from zero.
     */
    public static function summedCost(Connection $connection): string
    {
        return 'cast(round(sum(quantity * unit_cost) / 1000.0) as '.self::integerType($connection).')';
    }

    /**
     * One row's money in a query, rounded the way `cost()` rounds it, for
     * summing a Supply's lines as an invoice adds them up.
     */
    public static function lineCost(Connection $connection): string
    {
        return 'cast(round(quantity * unit_cost / 1000.0) as '.self::integerType($connection).')';
    }

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
     * How many packets of a Pack these grams make, or null where they leave a
     * remainder: 2000 in 250 g -> 8, 1000 in 400 g -> null. Never rounded — a
     * weight a Pack cannot make exactly is refused, not quietly changed.
     */
    public static function packetsIn(int $grams, int $packGrams): ?int
    {
        self::ensurePackWeighsSomething($packGrams);

        return $grams % $packGrams === 0 ? intdiv($grams, $packGrams) : null;
    }

    /**
     * What so many packets of a Pack weigh: 10 × 250 g -> 2500.
     */
    public static function ofPackets(int $packets, int $packGrams): int
    {
        self::ensurePackWeighsSomething($packGrams);

        return $packets * $packGrams;
    }

    /**
     * The whole kilograms a weight is charged delivery by (ADR-0039): rounded
     * up, so 350 g -> 1 and 2,250 g -> 3; nothing for nothing.
     */
    public static function deliveryKilograms(int $grams): int
    {
        return $grams <= 0 ? 0 : intdiv($grams + 999, 1000);
    }

    /**
     * The whole kilograms nearest to these that a Pack makes exactly, for a
     * refusal to name: the one below and the one above, or the first two where
     * none lies below — 1 kg in 400 g -> [2, 4], 5 kg in 400 g -> [4, 6]. Just
     * these kilograms where the Pack already makes them.
     *
     * @return list<int>
     */
    public static function workableKilograms(int $kilograms, int $packGrams): array
    {
        self::ensurePackWeighsSomething($packGrams);

        $step = intdiv($packGrams, self::greatestCommonDivisor(self::PER_KILOGRAM, $packGrams));
        $below = intdiv($kilograms, $step) * $step;

        return match (true) {
            $below === $kilograms => [$kilograms],
            $below <= 0 => [$step, 2 * $step],
            default => [$below, $below + $step],
        };
    }

    /**
     * What these grams are worth at a cost per kilogram, in minor units.
     */
    public static function cost(int $grams, int $costPerKilogram): int
    {
        return (int) round($grams * $costPerKilogram / self::PER_KILOGRAM);
    }

    /**
     * A cost per packet of a Pack as the cost per kilogram the ledger stamps,
     * in minor units: ৳100 a 250 g packet -> ৳400 a kilogram. Rounded half
     * away from zero where the Pack does not divide a kilogram.
     */
    public static function costPerKilogram(int $costPerPacket, int $packGrams): int
    {
        self::ensurePackWeighsSomething($packGrams);

        return (int) round($costPerPacket * self::PER_KILOGRAM / $packGrams);
    }

    /**
     * A Σ(grams × cost per kg) read back from the database as the money it
     * comes to, in minor units, or null where nothing was summed.
     */
    public static function costOfSum(mixed $summed): ?int
    {
        return $summed === null ? null : (int) round(((float) $summed) / self::PER_KILOGRAM);
    }

    private static function ensurePackWeighsSomething(int $packGrams): void
    {
        if ($packGrams < 1) {
            throw new InvalidArgumentException("A Pack must weigh at least a gram, not {$packGrams}.");
        }
    }

    /**
     * The integer a cast in a query lands on: MySQL and MariaDB have no
     * `cast(… as bigint)` and only know `signed`, which SQLite and PostgreSQL
     * do not.
     */
    private static function integerType(Connection $connection): string
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'signed' : 'bigint';
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        return $b === 0 ? $a : self::greatestCommonDivisor($b, $a % $b);
    }
}
