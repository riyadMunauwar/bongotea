<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Tables;

use App\Models\AdjustmentCause;
use App\Models\StockCount;
use App\Support\Inventory\Grams;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything anybody has adjusted, newest first, in the words the dialog used
 * (ADR-0040): how much it changed, what it came to, and why.
 *
 * No row actions and no bulk actions at all: there is nothing to do to an
 * adjustment that has been made. What a User does from here is adjust
 * something else, which is the header action on the list page.
 */
class StockCountsTable
{
    /** The filter's words for the two reasons nobody picks. */
    private const OPENING = 'opening';

    private const CHECKED = 'checked';

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            /** Filament loads what a dotted column names; the Adjustment and the cause are read in closures, so they are asked for here. */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['stockMovements', 'adjustmentCause']))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                /**
                 * What the ledger did, read off the Adjustment this count
                 * caused rather than worked out again here — the difference is
                 * the ledger's answer and this is a screen.
                 *
                 * Signed, so a change reads as the direction it went. An
                 * opening count draws nothing here: there was no figure to
                 * change from.
                 */
                TextColumn::make('change')
                    ->label('Change')
                    ->state(fn (StockCount $record): ?int => $record->is_opening ? null : $record->stockMovements->first()?->quantity)
                    ->formatStateUsing(fn (?int $state): ?string => match (true) {
                        $state === null => null,
                        $state > 0 => '+'.Grams::format($state),
                        default => Grams::format($state),
                    })
                    ->color(fn (?int $state): ?string => match (true) {
                        $state === null => null,
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('quantity')
                    ->label('New on hand')
                    ->formatStateUsing(fn (int $state): string => Grams::format($state))
                    ->sortable(),

                /** The Merchant's own cause, or one of the two facts the ledger records in place of one, drawn quietly. */
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (StockCount $record): string => match (true) {
                        $record->is_opening => 'Opening count',
                        $record->adjustmentCause === null => 'Checked',
                        default => $record->adjustmentCause->name,
                    })
                    ->badge()
                    ->color(fn (StockCount $record): string => $record->adjustmentCause?->direction->getColor() ?? 'gray')
                    ->extraAttributes(fn (StockCount $record): array => $record->adjustmentCause === null ? ['class' => 'italic'] : []),

                TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->toggleable(),

                /** Who adjusted, so a surprising figure has somebody to ask about it. */
                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                /** Every cause, retired ones included: "what did I lose to damage this year" still has an answer after Damaged is retired. */
                SelectFilter::make('reason')
                    ->label('Reason')
                    /** `+` rather than a spread, which would renumber the ids it is keyed by. */
                    ->options(fn (): array => AdjustmentCause::query()->orderBy('name')->pluck('name', 'id')->all() + [
                        self::OPENING => 'Opening count',
                        self::CHECKED => 'Checked',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        self::OPENING => $query->where('is_opening', true),
                        self::CHECKED => $query->where('is_opening', false)->whereNull('adjustment_cause_id'),
                        default => $query->where('adjustment_cause_id', $data['value']),
                    }),
            ]);
    }
}
