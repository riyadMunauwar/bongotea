<?php

namespace App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Tables;

use App\Models\AdjustmentCause;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every reason, retired ones included, with how many Adjustments each
 * explains — which is what decides whether it can still be deleted.
 */
class AdjustmentCausesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label('Reason')
                    ->searchable(),

                TextColumn::make('direction')
                    ->badge(),

                TextColumn::make('stock_counts_count')
                    ->label('Used')
                    ->counts('stockCounts')
                    ->numeric(),

                TextColumn::make('retired_at')
                    ->label('Retired')
                    ->date()
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),

                /** Off the dialog's list from now on, and still on every Adjustment it explains. */
                Action::make('retire')
                    ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (AdjustmentCause $record): bool => ! $record->isRetired())
                    ->action(fn (AdjustmentCause $record) => $record->retire()),

                Action::make('reinstate')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (AdjustmentCause $record): bool => $record->isRetired())
                    ->action(fn (AdjustmentCause $record) => $record->reinstate()),

                /** Only while nothing carries it: deleting a used reason would leave Adjustments explaining nothing. */
                DeleteAction::make()
                    ->visible(fn (AdjustmentCause $record): bool => ! $record->isUsed()),
            ]);
    }
}
