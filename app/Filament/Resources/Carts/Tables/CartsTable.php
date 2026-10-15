<?php

namespace App\Filament\Resources\Carts\Tables;

use App\Models\Cart;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * The Carts in the order Customers are shown them, dragged to reorder, each
 * switched on or off from the list.
 *
 * No bulk delete: a Cart is deleted one at a time, from its editor.
 */
class CartsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),

                TextColumn::make('kind')
                    ->badge(),

                ToggleColumn::make('is_switched_on')
                    ->label('On'),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),

                /** Customers reach a Cart only through the Page its Cart Block stands on. */
                TextColumn::make('page')
                    ->label('Page')
                    ->state(fn (Cart $record): ?string => $record->page()?->title)
                    ->description(fn (Cart $record): ?string => $record->link())
                    ->placeholder('On no Page'),
            ])
            ->filters([
                TrashedFilter::make()
                    ->label('Deleted'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
