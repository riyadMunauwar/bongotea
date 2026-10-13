<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Tables;

use App\Enums\AdjustmentCause;
use App\Models\StockCount;
use App\Support\Inventory\Grams;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything anybody has counted, newest first.
 *
 * No row actions and no bulk actions at all: there is nothing to do to a
 * count that has been taken. What a User does from here is count something
 * else, which is the header action on the list page.
 */
class StockCountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            /** Filament loads what a dotted column names; the Adjustment is read in a closure, so it is asked for here. */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('stockMovements'))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Counted')
                    ->formatStateUsing(fn (int $state): string => Grams::toKilograms($state))
                    ->sortable(),

                /**
                 * What the ledger did about it, read off the Adjustment this
                 * count caused rather than worked out again here — the
                 * difference is the ledger's answer and this is a screen.
                 *
                 * Signed, so a correction reads as the direction it went. A
                 * count that agreed with the ledger shows `0`, which is the
                 * true and useful answer: somebody looked, and nothing was
                 * out.
                 */
                TextColumn::make('adjustment')
                    ->label('Adjustment')
                    ->state(fn (StockCount $record): ?int => $record->stockMovements->first()?->quantity)
                    ->formatStateUsing(fn (?int $state): ?string => match (true) {
                        $state === null => null,
                        $state > 0 => '+'.Grams::toKilograms($state),
                        default => Grams::toKilograms($state),
                    })
                    ->color(fn (?int $state): ?string => match (true) {
                        $state === null => null,
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('cause')
                    ->label('Cause')
                    ->badge()
                    ->sortable(),

                TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->toggleable(),

                /** Who counted, so a surprising correction has somebody to ask about it. */
                TextColumn::make('user.name')
                    ->label('Counted by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Counted at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('cause')
                    ->options(AdjustmentCause::class),
            ]);
    }
}
