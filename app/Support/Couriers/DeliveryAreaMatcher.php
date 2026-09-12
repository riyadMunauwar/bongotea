<?php

namespace App\Support\Couriers;

/**
 * The system's best guess at which of a Courier's own delivery areas an Order
 * is going to, from the address the Customer typed and the District they
 * agreed.
 *
 * A pure function over three values and nothing else: no HTTP, no database,
 * no Settings, no screen. That is deliberate and is the whole reason this is
 * a class of its own rather than a method on the driver that needs it — it is
 * the one piece of this feature whose behaviour is a table of cases rather
 * than a conversation with a Courier, so it is the one piece tested directly
 * and exhaustively (ADR-0020's seam, and the one sanctioned exception to it).
 *
 * It guesses and never decides. A guess goes in front of a User pre-selected
 * and is theirs to correct, because a parcel sent to a guessed depot is a
 * parcel in the wrong city and a Merchant paying for it twice. So the rules
 * below all lean the same way: an area is matched on its whole name or not at
 * all, a name spelled out inside another gives way to it, and anything this
 * cannot settle answers with nothing rather than with a coin-toss between two
 * depots.
 */
final class DeliveryAreaMatcher
{
    /**
     * The Courier's area this address is most likely to mean, or nothing
     * where the guess cannot be settled.
     *
     * The address decides wherever it names an area at all, and the District
     * only settles which of the areas it named is meant. Where the address
     * named none — it is a landmark, or a road nobody's list has heard of —
     * the District stands in for it, and answers only where the Courier
     * serves exactly one area there: a District with eleven areas in it has
     * not told us which door.
     *
     * An address that named two areas answers with nothing rather than
     * falling back to the District, because a text reading "Banani, opposite
     * Uttara Tower" is not a text that said nothing: it said two things, and
     * which of them is the door is a question for the human who has the
     * Customer on the phone.
     *
     * @param  list<DeliveryArea>  $areas
     */
    public static function guess(?string $address, ?string $district, array $areas): ?DeliveryArea
    {
        $named = self::named($address, $areas);

        return self::settle($named === [] ? self::within($district, $areas) : $named, $district);
    }

    /**
     * Every area the Courier serves in this District.
     *
     * The District is structured data an Order holds and an area carries
     * (CONTEXT: District), so this is a comparison and never a search through
     * the address: it is what is left to go on when the address named nothing,
     * and it is worth something only where it narrows to one.
     *
     * @param  list<DeliveryArea>  $areas
     * @return list<DeliveryArea>
     */
    private static function within(?string $district, array $areas): array
    {
        if (($district = self::words($district)) === '') {
            return [];
        }

        return array_values(array_filter(
            $areas,
            fn (DeliveryArea $area): bool => self::words($area->district) === $district,
        ));
    }

    /**
     * Every area whose whole name this text names, less any whose name
     * another named area's name spells out in full.
     *
     * Whole names only: "Mirpur 1" is not named by an address reading "Mirpur
     * 10", and "Banani" is not named by one reading "Bonani" — a near miss is
     * a miss, because a guess that corrects a Customer's spelling is a guess
     * that invents a destination.
     *
     * The one name dropped is the one already inside another: an address
     * reading "Banani DOHS" names Banani as well, and it is the DOHS depot
     * that was meant. That is containment and never length — two areas an
     * address names in different breaths are two answers, whatever their
     * names happen to be the length of, and the longer of them is not the
     * more likely. Areas sharing a name exactly are both kept, so that the
     * District can tell them apart.
     *
     * @param  list<DeliveryArea>  $areas
     * @return list<DeliveryArea>
     */
    private static function named(?string $text, array $areas): array
    {
        $haystack = self::words($text);

        if ($haystack === '') {
            return [];
        }

        /** @var list<array{DeliveryArea, string}> $named */
        $named = [];

        foreach ($areas as $area) {
            $name = self::words($area->name);

            if ($name !== '' && self::names($haystack, $name)) {
                $named[] = [$area, $name];
            }
        }

        $kept = [];

        foreach ($named as [$area, $name]) {
            foreach ($named as [, $other]) {
                if ($other !== $name && self::names($other, $name)) {
                    continue 2;
                }
            }

            $kept[] = $area;
        }

        return $kept;
    }

    /** Whether this text names this area's name as a run of whole words rather than as part of a longer one. */
    private static function names(string $text, string $name): bool
    {
        return str_contains(" {$text} ", " {$name} ");
    }

    /**
     * Which of the candidate areas is meant, where there is more than one.
     *
     * The District is the only tie-breaker, and it only ever breaks a tie:
     * couriers list the same name in several Districts — every District has a
     * Sadar — and the Order's own District is exactly what tells them apart.
     * A tie the District does not break is left unbroken.
     *
     * @param  list<DeliveryArea>  $candidates
     */
    private static function settle(array $candidates, ?string $district): ?DeliveryArea
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($candidates === []) {
            return null;
        }

        $here = self::within($district, $candidates);

        return count($here) === 1 ? $here[0] : null;
    }

    /**
     * A text reduced to its words, lowercased and separated by single spaces,
     * so that punctuation, case and a post code cannot come between an
     * address and the area it names.
     *
     * Whole words are what the separators are for: reducing to letters and
     * digits alone would have "Mirpur 1" match "Mirpur 10".
     */
    private static function words(?string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $text));

        return trim((string) $text);
    }
}
