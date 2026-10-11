<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Resources\Menus\MenuResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Asks only for the name, then lands on the builder, which is the edit page.
 */
class CreateMenu extends CreateRecord
{
    protected static string $resource = MenuResource::class;

    protected static bool $canCreateAnother = false;
}
