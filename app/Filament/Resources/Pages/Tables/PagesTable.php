<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\PageStatus;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->prefix('/')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PageStatus $state): string => match ($state) {
                        PageStatus::Published => 'success',
                        PageStatus::Draft => 'gray',
                    }),

                TextColumn::make('products.name')
                    ->label('Product')
                    ->listWithLineBreaks()
                    ->placeholder('None'),

                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PageStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                /**
                 * Preview runs the same expansion pipeline behind admin auth,
                 * so the merchant can look at a page without publishing it —
                 * publishing being the act that makes it reachable to ad
                 * traffic.
                 */
                Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Page $record): string => route('pages.preview', $record))
                    ->openUrlInNewTab(),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
