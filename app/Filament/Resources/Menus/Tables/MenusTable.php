<?php

namespace App\Filament\Resources\Menus\Tables;

use App\Filament\Resources\Menus\MenuResource;
use App\Models\Menu;
use App\Support\Menus\MenuPlaces;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every Menu with how many links it holds and where it is drawn.
 *
 * There is no bulk delete: each delete is checked against the places that
 * draw the Menu, one at a time, and refused with them named.
 */
class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Links')
                    ->counts('items'),

                TextColumn::make('assigned_to')
                    ->label('Assigned to')
                    ->state(fn (Menu $record): array => MenuPlaces::of($record))
                    ->badge()
                    ->placeholder('Nowhere'),
            ])
            ->recordActions([
                EditAction::make(),
                MenuResource::refuseDeletingAssigned(DeleteAction::make()),
            ]);
    }
}
