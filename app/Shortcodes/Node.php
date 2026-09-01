<?php

namespace App\Shortcodes;

/**
 * The seam between a Shortcode and a Block.
 *
 * A Shortcode arrives at a node by parsing `[checkout]` out of authored HTML;
 * a Block will arrive at the same node by storing it from an editor. Because
 * both sides hand a definition the same thing, and a definition has exactly one
 * render(), the two can never drift. See ADR-0002.
 */
class Node
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
    ) {}

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
}
