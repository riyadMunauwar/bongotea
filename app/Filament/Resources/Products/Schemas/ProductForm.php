<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Support\Money;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                /**
                 * Money is stored as integer minor units. The conversion happens
                 * here, at the boundary, so nothing between this field and the
                 * database has to know what a minor unit is worth.
                 */
                TextInput::make('price')
                    ->label('Price ('.config('giopages.currency.code').')')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix(config('giopages.currency.symbol'))
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajorString($state))
                    ->dehydrateStateUsing(fn (string $state): int => Money::toMinorUnits($state)),

                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),

                SpatieMediaLibraryFileUpload::make('image')
                    ->collection(Product::IMAGE_COLLECTION)
                    ->image()
                    ->imageEditor()
                    ->columnSpanFull(),
            ]);
    }
}
