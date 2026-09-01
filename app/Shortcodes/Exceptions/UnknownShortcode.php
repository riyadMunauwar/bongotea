<?php

namespace App\Shortcodes\Exceptions;

use RuntimeException;

class UnknownShortcode extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self("No shortcode is registered under the name [{$name}].");
    }
}
