<?php

namespace App\Shortcodes;

use App\Shortcodes\Exceptions\UnknownShortcode;

/**
 * Maps a name to its Shortcode Definition. The parser matches registered names
 * only, so this registry is also the boundary of the syntax we claim over a
 * merchant's document.
 */
class ShortcodeRegistry
{
    /** @var array<string, ShortcodeDefinition> */
    private array $definitions = [];

    public function register(ShortcodeDefinition $definition): void
    {
        $this->definitions[$definition->name()] = $definition;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): ShortcodeDefinition
    {
        return $this->definitions[$name] ?? throw UnknownShortcode::named($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return array<string, ShortcodeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
