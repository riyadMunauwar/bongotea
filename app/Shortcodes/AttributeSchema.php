<?php

namespace App\Shortcodes;

use App\Shortcodes\Exceptions\InvalidShortcodeAttributes;

/**
 * A Shortcode Definition's declared attributes.
 *
 * v1 ships this unexercised — `[checkout]` declares none — and that is
 * deliberate. The schema is the part that makes a block builder possible, and
 * adding it after definitions exist means revisiting every one. See ADR-0002.
 */
class AttributeSchema
{
    /** @var array<string, AttributeDefinition> */
    private readonly array $attributes;

    public function __construct(AttributeDefinition ...$attributes)
    {
        $keyed = [];

        foreach ($attributes as $attribute) {
            $keyed[$attribute->name] = $attribute;
        }

        $this->attributes = $keyed;
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * @return array<string, AttributeDefinition>
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Turn raw parsed strings into typed values, applying defaults.
     *
     * @param  array<string, string>  $raw
     * @return array<string, scalar|null>
     *
     * @throws InvalidShortcodeAttributes
     */
    public function coerce(string $shortcodeName, array $raw): array
    {
        if ($unknown = array_diff(array_keys($raw), array_keys($this->attributes))) {
            throw InvalidShortcodeAttributes::unknown($shortcodeName, array_values($unknown));
        }

        $coerced = [];

        foreach ($this->attributes as $name => $attribute) {
            if (! array_key_exists($name, $raw)) {
                if ($attribute->required) {
                    throw InvalidShortcodeAttributes::missing($shortcodeName, $name);
                }

                $coerced[$name] = $attribute->default;

                continue;
            }

            $coerced[$name] = $attribute->type->coerce($raw[$name]);
        }

        return $coerced;
    }
}
