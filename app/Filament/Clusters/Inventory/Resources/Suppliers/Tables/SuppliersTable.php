<?php

namespace App\Filament\Clusters\Inventory\Resources\Suppliers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Everybody the Merchant buys from, with how much has come from each.
 *
 * The deliveries count is what makes this list worth reading rather than a
 * directory: a Supplier nobody has ever bought from is one somebody recorded
 * and never used, and the one that has delivered forty times is where the
 * money goes.
 */
class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('supplies_count')
                    ->label('Deliveries')
                    ->counts('supplies')
                    ->sortable(),

                TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            /**
             * Taken off the list and put back, and never force-deleted.
             * `supplies.supplier_id` is restricted rather than cascading, so
             * erasing a Supplier who ever delivered anything would either
             * fail at the database or take the buying history with it — and
             * the whole reason a Supplier is a record is that the history
             * holds together under one name.
             */
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
