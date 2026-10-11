<?php

namespace App\Support\Menus;

use App\Models\Menu;
use App\Support\Settings\FooterSettings;
use App\Support\Settings\HeaderSettings;

/**
 * Where a Menu is assigned, asked of each place's settings group.
 *
 * A place holds the Menu's id; a Menu holds nothing about its places
 * (ADR-0033). So "assigned" is never a column or a second list: it is each
 * place group's own `placesOf()`, and a new place joins by being asked here.
 */
class MenuPlaces
{
    /**
     * @return list<string>
     */
    public static function of(Menu $menu): array
    {
        return [
            ...app(HeaderSettings::class)->placesOf($menu->id),
            ...app(FooterSettings::class)->placesOf($menu->id),
        ];
    }
}
