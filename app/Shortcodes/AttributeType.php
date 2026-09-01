<?php

namespace App\Shortcodes;

/**
 * The types an attribute schema can declare. Deliberately small: every value
 * arrives as a string from the parser, and the set has to be one a Filament
 * form field can be generated from when the block builder lands.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';

    public function coerce(string $raw): string|int|bool
    {
        return match ($this) {
            self::Text => $raw,
            self::Integer => (int) $raw,
            self::Boolean => filter_var($raw, FILTER_VALIDATE_BOOL),
        };
    }
}
