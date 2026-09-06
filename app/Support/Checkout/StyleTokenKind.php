<?php

namespace App\Support\Checkout;

/**
 * What kind of value a Style Token holds.
 *
 * It decides two things and nothing else: which control the Checkout tab asks
 * for the value with, and what a value has to look like to be accepted. Both
 * live here rather than beside each token, because every colour is refused for
 * the same reasons and every length is refused for the same reasons.
 *
 * A refusal is worded for the Merchant reading it on the Checkout tab. The same
 * check runs again as a Config is read, where an unusable value is dropped
 * rather than explained — see `StyleTokens::fromArray()`.
 */
enum StyleTokenKind
{
    case Colour;
    case Length;
    case Scale;
    case Width;

    /**
     * The units a length may carry.
     *
     * Absolute and root-relative only. A percentage or a viewport unit would
     * resolve against something the Checkout does not own — the containing
     * element a Merchant dropped the shortcode into, or the phone the visitor
     * is holding — so a border width typed once would land differently on every
     * Page it was copied to.
     */
    private const UNITS = ['px', 'rem', 'em'];

    /**
     * Colours that are not a colour but an instruction.
     *
     * `inherit` is how the Checkout takes its text colour from the Page it sits
     * in, which is what it has always done and what the text token defaults to
     * — so this list is what stops the read side refusing our own default.
     *
     * The other two are accepted rather than offered. The Checkout tab asks for
     * a colour with a picker, which types hex and nothing else, so no Merchant
     * arrives here holding one; they are allowed because they are real colours,
     * and being stricter than CSS about a value that cannot break anything
     * would only refuse a Config somebody wrote deliberately.
     */
    private const KEYWORDS = ['inherit', 'transparent', 'currentcolor'];

    /**
     * The units a width may carry.
     *
     * The three lengths, plus the one relative unit that resolves against the
     * element the Merchant put the shortcode in rather than against something
     * else: a percentage. `100%` is how a Checkout is asked to fill whatever it
     * was placed in.
     *
     * A viewport unit is refused because it resolves against neither. `100vw`
     * is the width of the phone, so a Checkout dropped into a padded column
     * would break out of the column and out of the Merchant's design.
     *
     * That refusal is half of "full width never escapes the element the
     * shortcode sits in". The other half is not stated here and is not this
     * pattern's to state: the island is an auto-width block, so it is already
     * no wider than what it sits in, and a `max-width` only ever narrows it. A
     * figure above 100% is accepted for that reason rather than refused — it is
     * a real value that simply never applies.
     */
    private const WIDTH_UNITS = ['px', 'rem', 'em', '%'];

    /**
     * Said of a size chosen from a list rather than typed.
     *
     * Worded here beside the other refusals, and said by `StyleToken`, which is
     * what holds the list a scale is chosen from.
     */
    public const SCALE_REFUSAL = 'Choose one of the sizes listed.';

    /**
     * Why this value may not be stored, or nothing where it may.
     *
     * Refusing rather than sanitising is the whole of "no value entered in the
     * admin can break out of the emitted style block": what survives this is a
     * hex triplet, a colour function whose arguments are digits and units, a
     * number with a unit, or one of three keywords. None of them can carry a
     * semicolon, a brace or a `<`, so none of them can end the declaration they
     * are written into.
     */
    public function refusal(string $value): ?string
    {
        return match ($this) {
            self::Colour => self::colourRefusal($value),
            self::Length => self::lengthRefusal($value),
            self::Width => self::widthRefusal($value),

            /**
             * Never reached: a scale is chosen from a list, so the token itself
             * answers for it rather than a pattern. Answered anyway, so adding
             * a kind is a compile error rather than a value nothing checks.
             */
            self::Scale => self::SCALE_REFUSAL,
        };
    }

    /**
     * A colour has to parse, and the parser is this pattern rather than a
     * browser.
     *
     * Named colours are refused. There is no list of them here to check against
     * and a name nothing recognises is not an error a browser reports — it
     * drops the declaration that reads the token and leaves the Merchant
     * looking at a Checkout that ignored what they typed. A picker types hex,
     * so nothing on the Checkout tab produces a name in the first place.
     */
    private static function colourRefusal(string $value): ?string
    {
        if (in_array(strtolower($value), self::KEYWORDS, true)) {
            return null;
        }

        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1) {
            return null;
        }

        /** Digits, separators and units only: no name, no nesting, nothing that closes a declaration. */
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9a-z%.,\/\s-]+\)$/i', $value) === 1) {
            return null;
        }

        return 'That is not a colour we can read. Use something like #1f2937 or rgb(31, 41, 55).';
    }

    /**
     * A length is a number and one of the units listed, or a bare zero, which
     * is the one number that needs no unit and the one a Merchant squaring off
     * every corner will type.
     *
     * Negatives are refused rather than floored. There is no negative radius,
     * border width or font size, so a minus sign is a typo, and a typo silently
     * turned into something else is how a Merchant ends up unable to explain
     * what their Page looks like.
     */
    private static function lengthRefusal(string $value): ?string
    {
        if ($value === '0') {
            return null;
        }

        return self::unitRefusal(
            $value,
            self::UNITS,
            'Use a length like 8px, .5rem or 0. Units: '.implode(', ', self::UNITS).'.',
        );
    }

    /**
     * A width is a length, or a percentage of whatever the Checkout was placed
     * in, and never anything that resolves against the phone.
     *
     * A bare zero is not among them, unlike a length. There is no reason to ask
     * for a form no wider than nothing, and a Merchant who typed it would be
     * looking at an invisible Checkout with nothing to tell them why.
     */
    private static function widthRefusal(string $value): ?string
    {
        return self::unitRefusal(
            $value,
            self::WIDTH_UNITS,
            'Use a width like 30rem or 640px, or 100% to fill whatever the shortcode sits in. Viewport units are refused: the form never escapes the element it was placed in.',
        );
    }

    /**
     * A number carrying one of the units listed, or the refusal given.
     *
     * The one shape both sizes are read with, so a kind differs by the units it
     * accepts and the words it refuses in — never by a second copy of the
     * pattern. What survives it is digits and a unit, which is what keeps a
     * stored value from ending the declaration it is written into.
     *
     * @param  list<string>  $units
     */
    private static function unitRefusal(string $value, array $units, string $refusal): ?string
    {
        $units = implode('|', array_map(preg_quote(...), $units));

        return preg_match('/^(?:\d+|\d*\.\d+)(?:'.$units.')$/', $value) === 1 ? null : $refusal;
    }
}
