<?php

namespace App\Shortcodes;

/**
 * One attribute a Shortcode Definition accepts. What the parser validates
 * against today, and what a Filament repeater generates a form field from once
 * blocks exist.
 */
class AttributeDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly AttributeType $type = AttributeType::Text,
        public readonly bool $required = false,
        public readonly string|int|bool|null $default = null,
        public readonly ?string $label = null,
    ) {}
}
