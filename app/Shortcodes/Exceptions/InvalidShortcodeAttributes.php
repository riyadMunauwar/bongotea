<?php

namespace App\Shortcodes\Exceptions;

use RuntimeException;

class InvalidShortcodeAttributes extends RuntimeException
{
    /**
     * @param  list<string>  $names
     */
    public static function unknown(string $shortcode, array $names): self
    {
        return new self(sprintf(
            '[%s] does not accept the attribute%s %s.',
            $shortcode,
            count($names) === 1 ? '' : 's',
            implode(', ', $names),
        ));
    }

    public static function missing(string $shortcode, string $name): self
    {
        return new self("[{$shortcode}] requires the attribute {$name}.");
    }
}
