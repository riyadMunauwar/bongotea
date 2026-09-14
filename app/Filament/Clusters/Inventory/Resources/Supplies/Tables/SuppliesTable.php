<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Tables;

use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything that has been bought, newest delivery first.
 *
 * No edit, no delete and no bulk action: a delivery recorded is a delivery
 * that happened, and what a User does about a wrong one is Void it. What they
 * do from here is open one, or record another.
 */
class SuppliesTable
{
    /** What the summed line costs are loaded under, named once so the column and its sort agree. */
    public const TOTAL_COST = 'total_cost';

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('supplied_on', 'desc')
            /** What a delivery cost is the sum of its lines, summed in the query the list already runs rather than a row at a time. */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum(
                'lines as '.self::TOTAL_COST,
                DB::raw('quantity * unit_cost'),
            ))
            ->columns([
                TextColumn::make('supplied_on')
                    ->label('Delivered')
                    ->date()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->counts('lines'),

                /** Nothing rather than a free delivery where there is somehow nothing to sum, the way every other money figure here reads. */
                TextColumn::make(self::TOTAL_COST)
                    ->label('Cost')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::format($state))
                    ->sortable()
                    ->placeholder('—'),

                /** Who recorded it, so a surprising figure has somebody to ask about it. */
                TextColumn::make('user.name')
                    ->label('Recorded by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Recorded at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
