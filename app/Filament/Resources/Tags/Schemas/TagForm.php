<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Filament\Concerns\HasSlugField;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Drawn in a dialog over the Tags list, the three fields on one line.
 */
class TagForm
{
    use HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 3])
            ->components([
                self::nameInput(),

                /** A Tag has no page; the slug is what a Tag filter names in an address. */
                self::slugInput(),

                TextInput::make('sort_order')
                    ->label('Sort order')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
            ]);
    }
}
