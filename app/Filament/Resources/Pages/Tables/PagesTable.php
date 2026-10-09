<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\PageStatus;
use App\Enums\Permission;
use App\Models\Page;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PagesTable
{
    /**
     * A System Page cannot be ticked, so a bulk delete never reaches one.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->formatStateUsing(fn (string $state, Page $record): string => $record->path())
                    ->searchable()
                    ->copyable(),

                TextColumn::make('system_role')
                    ->label('System Page')
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PageStatus $state): string => match ($state) {
                        PageStatus::Published => 'success',
                        PageStatus::Draft => 'gray',
                    }),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PageStatus::class),
            ])
            ->checkIfRecordIsSelectableUsing(fn (Page $record): bool => ! $record->isSystemPage())
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can(Permission::DeletePages) ?? false),
                ]),
            ]);
    }
}
