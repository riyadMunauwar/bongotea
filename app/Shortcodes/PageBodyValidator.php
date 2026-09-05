<?php

namespace App\Shortcodes;

/**
 * The save-time linter.
 *
 * Parse-on-render is about not *storing* the expansion; it does not mean the
 * parser sits idle until a visitor arrives. Every authoring error below is
 * caught here, at the merchant's desk, instead of being discovered from a flat
 * conversion graph a day into a campaign.
 *
 * Errors block *publishing* only. A draft is a work in progress and may hold a
 * half-written shortcode; a published Page is on paid traffic.
 */
class PageBodyValidator
{
    /**
     * Anything shaped like a shortcode. Only ever used to *suggest* a
     * correction, never to match for rendering.
     */
    private const SHORTCODE_SHAPED = '/\[(?P<name>[A-Za-z][A-Za-z0-9_-]*)(?:\s[^\]\n]*)?\]/';

    /**
     * How far a typo may stray from a registered name and still be flagged.
     * Two edits catches `chekout` and `checout` without dragging in every
     * unrelated bracketed word in a stylesheet or a script.
     */
    private const TYPO_DISTANCE = 2;

    public function __construct(
        private readonly ShortcodeRegistry $registry,
        private readonly ShortcodeParser $parser,
    ) {}

    /**
     * Reasons this body may not be published, in the order a merchant should
     * fix them.
     *
     * @return list<string>
     */
    public function errors(string $body, int $linkedProductCount): array
    {
        $errors = [];

        $checkouts = count(array_filter(
            $this->parser->parse($body),
            fn (Node $node): bool => $node->name === Definitions\CheckoutShortcode::NAME,
        ));

        if ($checkouts > 1) {
            /** One Page captures one Order. Two forms means two Orders and Delivery Charge collected twice. */
            $errors[] = 'This page has '.$checkouts.' [checkout] shortcodes. A page may hold at most one.';
        }

        /**
         * One is the floor, not the ceiling. A Page may sell a combo or an
         * upsell alongside its headline item; what it may not do is go live
         * with a form that has nothing behind it.
         */
        if ($checkouts > 0 && $linkedProductCount === 0) {
            $errors[] = 'This page has a [checkout] shortcode but no linked product, so there is nothing to sell.';
        }

        return $errors;
    }

    /**
     * Likely typos: bracketed words close enough to a registered name to be a
     * misspelling of it.
     *
     * A warning rather than an error on purpose. The renderer matches
     * registered names only, so a legitimate `[0]` or `[href]` in a script or a
     * stylesheet is never in danger — which means strictness here can be
     * generous without ever blocking a save.
     *
     * @return list<string>
     */
    public function warnings(string $body): array
    {
        preg_match_all(self::SHORTCODE_SHAPED, $body, $matches);

        $registered = $this->registry->names();
        $warnings = [];

        foreach (array_unique($matches['name'] ?? []) as $candidate) {
            if (in_array($candidate, $registered, true)) {
                continue;
            }

            foreach ($registered as $name) {
                if (levenshtein(strtolower($candidate), $name) <= self::TYPO_DISTANCE) {
                    $warnings[] = "[{$candidate}] looks like a shortcode but isn't one — did you mean [{$name}]?";

                    continue 2;
                }
            }
        }

        return array_values($warnings);
    }
}
