<?php

namespace App\Shortcodes;

/**
 * Turns `[checkout]` in authored HTML into a node.
 *
 * Two rules, both there to keep the merchant's document intact:
 *
 * 1. **Registered names only.** Matching anything shaped like `[word]` and then
 *    looking it up would false-positive on `arr[0]`, `a[href]` and JSON inside
 *    a `<script>` — it makes our syntax a claim over the whole document. Typos
 *    are caught by the save-time linter instead, which can warn without
 *    blocking.
 * 2. **Plain string replacement, never a DOM round-trip.** Any DOM parse
 *    re-serialises the document, and a merchant seeing their markup silently
 *    reformatted reads as "your product broke my page". Every byte that is not
 *    a registered shortcode comes out identical.
 */
class ShortcodeParser
{
    /**
     * Name, optional whitespace-separated quoted attributes, closing bracket.
     * Anchoring the attribute list to quoted values keeps a stray `[checkout`
     * in prose from swallowing the rest of the document.
     */
    private const ATTRIBUTES = '(?P<attributes>(?:\s+[A-Za-z_][A-Za-z0-9_-]*=(?:"[^"]*"|\'[^\']*\'))*)';

    public function __construct(private readonly ShortcodeRegistry $registry) {}

    /**
     * Replace every registered shortcode occurrence with the result of $expand.
     *
     * @param  callable(Node): string  $expand
     */
    public function replace(string $body, callable $expand): string
    {
        $pattern = $this->pattern();

        if ($pattern === null) {
            return $body;
        }

        return (string) preg_replace_callback(
            $pattern,
            fn (array $match): string => $expand($this->nodeFrom($match)),
            $body,
        );
    }

    /**
     * Every registered shortcode occurrence in the body, in document order.
     *
     * @return list<Node>
     */
    public function parse(string $body): array
    {
        $pattern = $this->pattern();

        if ($pattern === null || preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        return array_map(fn (array $match): Node => $this->nodeFrom($match), $matches);
    }

    /**
     * @param  array<string, string>  $match
     */
    private function nodeFrom(array $match): Node
    {
        return new Node($match['name'], $this->parseAttributes($match['attributes'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private function parseAttributes(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        preg_match_all(
            '/(?P<key>[A-Za-z_][A-Za-z0-9_-]*)=(?P<quoted>"[^"]*"|\'[^\']*\')/',
            $raw,
            $matches,
            PREG_SET_ORDER,
        );

        $attributes = [];

        foreach ($matches as $match) {
            /** The capture always carries its quotes, so trimming one character each end is exact. */
            $attributes[$match['key']] = substr($match['quoted'], 1, -1);
        }

        return $attributes;
    }

    /**
     * Null when the registry is empty: an alternation of nothing would match
     * every `[]` in the document.
     */
    private function pattern(): ?string
    {
        $names = $this->registry->names();

        if ($names === []) {
            return null;
        }

        $alternation = implode('|', array_map(preg_quote(...), $names));

        return '/\[(?P<name>'.$alternation.')'.self::ATTRIBUTES.'\s*\]/';
    }
}
