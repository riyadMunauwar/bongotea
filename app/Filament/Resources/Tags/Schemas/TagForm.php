<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Filament\Concerns\HasSlugField;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TagForm
{
    use HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /** A Tag has no page; the slug is what a Tag filter names in an address. */
                Section::make('Tag')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        self::nameInput(),

                        self::slugInput(),

                        TextInput::make('sort_order')
                            ->label('Sort order')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }
}
