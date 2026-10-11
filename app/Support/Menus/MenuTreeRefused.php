<?php

namespace App\Support\Menus;

use RuntimeException;

/**
 * A submitted Menu tree broke a rule, and nothing was written.
 *
 * `$path` points at the offending item as indexes from the top level down —
 * `[1, 0]` is the first child of the second item — which is also where it
 * sits in the builder's state, so the builder can mark it.
 */
class MenuTreeRefused extends RuntimeException
{
    /**
     * @param  list<int>  $path
     */
    public function __construct(public readonly array $path, public readonly ?string $label, string $reason)
    {
        parent::__construct(filled($label) ? "“{$label}”: {$reason}" : $reason);
    }
}
