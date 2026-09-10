<?php

namespace App\Filament\Resources\Destinations\Tables;

use App\Models\Destination;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DestinationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),

                TextColumn::make('dataset')
                    ->label('Dataset')
                    ->description(fn (Destination $record): string => $record->provider->datasetLabel())
                    ->searchable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),

                /**
                 * Why a Destination is not being delivered to, in the
                 * Provider's own wording, rather than a bare "off": the
                 * Merchant who pasted a dataset and forgot the token is the
                 * case this column exists for.
                 */
                TextColumn::make('refusal')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Destination $record): string => $record->refusal() ?? 'Reporting')
                    ->color(fn (Destination $record): string => $record->isUsable() ? 'success' : 'danger')
                    ->wrap(),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
