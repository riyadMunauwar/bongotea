<?php

namespace App\Filament\Resources\Menus\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * A Menu's name, which is only ever a label for whoever builds it: the
 * storefront never draws it, and renaming changes no place it appears.
 */
class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->helperText('For you only, e.g. “Main menu”. The storefront never shows it.')
                    ->required()
                    ->maxLength(60),
            ]);
    }
}
