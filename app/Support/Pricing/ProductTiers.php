<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

/**
 * The rule a Product's own Tiers answer to (ADR-0031): ranges covering every
 * whole kilogram from one without gap or overlap, the last open-ended, each
 * with a price, and no Tier dearer than one before it, because a heavier Cart
 * is never charged more. One Tier alone is a flat price.
 */
class ProductTiers
{
    /**
     * What is wrong with the Tiers, as a Merchant should read it, or null when
     * they can be saved. Values may arrive as a form types them; the prices
     * only need to share a unit.
     *
     * @param  array<array-key, mixed>  $tiers
     */
    public static function problem(array $tiers): ?string
    {
        $tiers = array_values(array_map(fn (mixed $tier): array => is_array($tier) ? $tier : [], $tiers));

        $rangeProblem = KilogramRanges::problem($tiers, 'Tier');

        if ($rangeProblem !== null) {
            return $rangeProblem;
        }

        $previous = null;

        foreach ($tiers as $index => $tier) {
            $position = $index + 1;
            $price = filter_var($tier['price'] ?? null, FILTER_VALIDATE_INT);

            if ($price === false || $price < 0) {
                return "Give the Tier in row {$position} a price of whole taka.";
            }

            if ($previous !== null && $price > $previous) {
                return "The Tier in row {$position} costs more than the one before it; a heavier cart is never charged more per kg.";
            }

            $previous = $price;
        }

        return null;
    }

    /**
     * A flat Product's one Tier: every weight at the same price.
     *
     * @return list<array{min: int, max: null, price: int}>
     */
    public static function flat(int $price): array
    {
        return [['min' => 1, 'max' => null, 'price' => $price]];
    }

    /**
     * Tiers that passed `problem()`, as the pricing module reads them.
     *
     * @param  array<array-key, mixed>  $tiers
     * @return non-empty-list<Tier>
     */
    public static function from(array $tiers): array
    {
        if (self::problem($tiers) !== null) {
            throw new InvalidArgumentException('A Product\'s Tiers must cover every kilogram once and never rise in price.');
        }

        return array_values(array_map(
            fn (array $tier): Tier => new Tier(
                (int) $tier['min'],
                ($tier['max'] ?? null) === null || $tier['max'] === '' ? null : (int) $tier['max'],
                (int) $tier['price'],
            ),
            $tiers,
        ));
    }
}
