<?php

namespace App\Support\Checkout;

/**
 * The typeface the Checkout is set in: Hind Siliguri, served from this site.
 *
 * Bangla is most of what a Checkout says, and a face that covers it well has
 * to come from somewhere. It comes from the app's own public directory rather
 * than a font service, because a landing page answering paid traffic should
 * make no request to anyone else on a visitor's behalf — a third party's
 * outage or slowness becoming ours, on the page that is spending money.
 *
 * The files are the four weights the designs use, each split the way the
 * service splits them: Bangla, Latin and the Latin extensions, with the
 * `unicode-range` that lets a browser fetch only the script a page actually
 * draws. That is twelve rules, emitted as one block, once, in the base sheet —
 * and only where the font token still names this family. A Merchant who set
 * the token to `inherit` asked for the Page's own typeface and gets no font
 * file at all; see `StyleTokens::fontFaces()`.
 */
final class CheckoutFont
{
    public const FAMILY = 'Hind Siliguri';

    /**
     * Where the files live under `public/`, and the stem each is named by.
     */
    public const DIRECTORY = 'fonts/hind-siliguri';

    /**
     * @var list<int>
     */
    public const WEIGHTS = [400, 500, 600, 700];

    /**
     * The scripts each weight is split into, and the code points each file
     * covers — as the service publishes them, so a browser downloads Bangla
     * for a Bangla word and nothing for a script the page never draws.
     *
     * @var array<string, string>
     */
    private const SUBSETS = [
        'bengali' => 'U+0951-0952, U+0964-0965, U+0980-09FE, U+1CD0, U+1CD2, U+1CD5-1CD6, U+1CD8, U+1CE1, U+1CEA, U+1CED, U+1CF2, U+1CF5-1CF7, U+200C-200D, U+20B9, U+25CC, U+A8F1',
        'latin-ext' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF',
        'latin' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
    ];

    /**
     * The font token's default: this family first, then what the visitor's
     * system has, as the designs specify.
     *
     * Unquoted on purpose. A family of two words is a valid unquoted name, and
     * the token's kind refuses quotes, so the value survives the template's
     * escaping on its way into the style block.
     */
    public static function stack(): string
    {
        return self::FAMILY.', system-ui, sans-serif';
    }

    /**
     * Whether a font stack asks for this family, quoted or not, wherever in the
     * list it sits.
     */
    public static function namedIn(string $stack): bool
    {
        return preg_match('/(?:^|,)\s*["\']?'.preg_quote(self::FAMILY, '/').'["\']?\s*(?:,|$)/i', $stack) === 1;
    }

    /**
     * The `@font-face` rules, one per weight and script, on a line each.
     */
    public static function faces(): string
    {
        $rules = [];

        foreach (self::WEIGHTS as $weight) {
            foreach (self::SUBSETS as $subset => $range) {
                $rules[] = '@font-face { font-family: "'.self::FAMILY.'"; font-style: normal; font-weight: '.$weight.'; font-display: swap;'
                    .' src: url(/'.self::file($weight, $subset).') format("woff2"); unicode-range: '.$range.'; }';
            }
        }

        return implode("\n", $rules);
    }

    /**
     * Every file the rules above point at, relative to `public/`.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::WEIGHTS as $weight) {
            foreach (array_keys(self::SUBSETS) as $subset) {
                $files[] = self::file($weight, $subset);
            }
        }

        return $files;
    }

    private static function file(int $weight, string $subset): string
    {
        return self::DIRECTORY.'/hind-siliguri-'.$weight.'-'.$subset.'.woff2';
    }
}
