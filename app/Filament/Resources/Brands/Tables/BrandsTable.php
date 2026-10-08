<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Models\Brand;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every Brand with how many Products carry it. No bulk delete, for the reason
 * `CategoriesTable` gives.
 */
class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo')
                    ->disk(Brand::LOGO_DISK)
                    ->label(''),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->color('gray'),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
