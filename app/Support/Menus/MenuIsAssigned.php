<?php

namespace App\Support\Menus;

use App\Models\Menu;
use RuntimeException;

/**
 * A Menu was asked to be deleted while a place still draws it.
 */
class MenuIsAssigned extends RuntimeException
{
    /**
     * @param  list<string>  $places
     */
    public static function to(Menu $menu, array $places): self
    {
        return new self("“{$menu->name}” can't be deleted: assigned to ".implode(', ', $places).'.');
    }
}
