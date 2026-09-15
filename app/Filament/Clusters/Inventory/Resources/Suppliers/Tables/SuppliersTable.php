<?php

namespace App\Filament\Clusters\Inventory\Resources\Suppliers\Tables;

use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Models\Supplier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everybody the Merchant buys from, with how much has come from each.
 *
 * The deliveries count is what makes this list worth reading rather than a
 * directory: a Supplier nobody has ever bought from is one somebody recorded
 * and never used, and the one that has delivered forty times is where the
 * money goes. It opens them, too — a Supplier's own buying history is one
 * click from their row, which is what a Supplier is a record for.
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

                /**
                 * The deliveries that stand. A voided one is a delivery that
                 * should never have been written down, so counting it here
                 * would tell a Merchant they buy from somebody more often
                 * than they do — the same reason the ledger takes its units
                 * back out.
                 */
                TextColumn::make('supplies_count')
                    ->label('Deliveries')
                    ->counts(['supplies' => fn (Builder $query): Builder => $query->notVoided()])
                    ->sortable()
                    /**
                     * And it opens them. A count of forty deliveries raises
                     * exactly one question — what were they? — and this is the
                     * answer, one click away: their own buying history, which
                     * is the whole reason a Supplier is a record rather than a
                     * name typed onto each delivery.
                     *
                     * The address is `SupplyResource`'s, built beside the
                     * filter it turns on, because a filter name Filament does
                     * not recognise is ignored in silence and would land a User
                     * on everybody's deliveries with nothing saying so.
                     */
                    ->url(fn (Supplier $record): string => SupplyResource::deliveriesFromUrl($record)),

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
