<?php

namespace App\Support\Checkout;

/**
 * What kind of value a Style Token holds.
 *
 * It decides two things and nothing else: which control the Look tab asks for
 * the value with, and what a value has to look like to be accepted. Both live
 * here rather than beside each token, because every colour is refused for the
 * same reasons and every length is refused for the same reasons.
 *
 * A refusal is worded for the Merchant reading it on the Look tab. The same
 * check runs again as a Config is read, where an unusable value is dropped
 * rather than explained — see `StyleTokens::fromArray()`.
 */
enum StyleTokenKind
{
    case Colour;
    case Length;
    case Font;

    /**
     * The units a length may carry.
     *
     * Absolute and root-relative only. A percentage or a viewport unit would
     * resolve against something the Checkout does not own — the containing
     * element a Merchant dropped the shortcode into, or the phone the visitor
     * is holding — so a radius typed once would land differently on every Page
     * it was copied to.
     */
    private const UNITS = ['px', 'rem', 'em'];

    /**
     * Colours that are not a colour but an instruction.
     *
     * `inherit` is how the Checkout takes a colour from the Page it sits in,
     * and `transparent` is how the ground behind it is left unpainted, which
     * is what the background token defaults to — so this list is what stops
     * the read side refusing our own default.
     *
     * `currentcolor` is accepted rather than offered. The Look tab asks for a
     * colour with a picker, which types hex and nothing else, so no Merchant
     * arrives here holding one; it is allowed because it is a real colour, and
     * being stricter than CSS about a value that cannot break anything would
     * only refuse a Config somebody wrote deliberately.
     */
    private const KEYWORDS = ['inherit', 'transparent', 'currentcolor'];

    /**
     * The words a browser reads as an instruction rather than a family name.
     *
     * `inherit`, on its own, is the one of them the font token means something
     * by. Each of them is otherwise a single bare word the family pattern
     * accepts, and a browser meeting one anywhere in a list drops the whole
     * declaration — so every name in the list is checked against them, and a
     * Merchant typing `initial` or `inherit, serif` gets a message rather than
     * a form set in whatever the browser's default happens to be.
     */
    private const FONT_KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer', 'default'];

    /**
     * The most a value of this kind may run to, in characters.
     *
     * Read by the Look tab as the box's `maxLength` and here as a refusal, so
     * the two cannot disagree. A font's is generous — the default is three
     * names — and there only because the value is emitted into every cached
     * page, so a stack nobody could have meant should not be paid for by every
     * visitor. A colour is bounded by its own pattern, so its figure is the
     * longest form the pattern accepts.
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::Colour => 40,
            self::Length => 20,
            self::Font => 120,
        };
    }

    /**
     * Why this value may not be stored, or nothing where it may.
     *
     * Refusing rather than sanitising is the whole of "no value entered in the
     * admin can break out of the emitted style block": what survives this is a
     * hex triplet, a colour function whose arguments are digits and units, a
     * number with a unit, a list of bare font names, or a keyword. None of
     * them can carry a semicolon, a brace, a quote or a `<`, so none of them
     * can end the declaration they are written into.
     */
    public function refusal(string $value): ?string
    {
        if (strlen($value) > $this->maxLength()) {
            return 'That is too long. Use at most '.$this->maxLength().' characters.';
        }

        return match ($this) {
            self::Colour => self::colourRefusal($value),
            self::Length => self::lengthRefusal($value),
            self::Font => self::fontRefusal($value),
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
     * so nothing on the Look tab produces a name in the first place.
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
     * so a minus sign is a typo, and a typo silently turned into something else
     * is how a Merchant ends up unable to explain what their Page looks like.
     */
    private static function lengthRefusal(string $value): ?string
    {
        if ($value === '0') {
            return null;
        }

        $units = implode('|', array_map(preg_quote(...), self::UNITS));

        return preg_match('/^(?:\d+|\d*\.\d+)(?:'.$units.')$/', $value) === 1
            ? null
            : 'Use a length like 8px, .5rem or 0. Units: '.implode(', ', self::UNITS).'.';
    }

    /**
     * A font is `inherit`, or a list of family names separated by commas.
     *
     * Names are bare words — letters, digits, hyphens and spaces — and never
     * quoted. CSS allows an unquoted family of several words (`Hind Siliguri`
     * is one), so quotes add nothing a Merchant needs, and refusing them is
     * what lets the value be written into a `<style>` block through the
     * template's own escaping without a `&quot;` landing in the declaration.
     * Generic families (`sans-serif`, `system-ui`) are words like any other.
     *
     * `inherit` is the Page's own typeface: the one value that is not a name,
     * and the one that asks for no font file at all — see
     * `StyleTokens::fontFaces()`.
     */
    private static function fontRefusal(string $value): ?string
    {
        if (strtolower($value) === 'inherit') {
            return null;
        }

        $family = '-?[a-z][a-z0-9_-]*(?:\s+[a-z0-9_-]+)*';

        $names = array_map(fn (string $name): string => strtolower(trim($name)), explode(',', $value));

        if (
            preg_match('/^'.$family.'(?:\s*,\s*'.$family.')*$/i', $value) === 1
            && array_intersect($names, self::FONT_KEYWORDS) === []
        ) {
            return null;
        }

        return 'Use font names separated by commas, like Hind Siliguri, system-ui, sans-serif — no quotes — or inherit to use the page\'s own font.';
    }
}
