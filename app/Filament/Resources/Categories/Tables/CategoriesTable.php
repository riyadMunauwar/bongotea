<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The Categories in the order the storefront draws them, dragged to reorder.
 *
 * No bulk delete: a Category is removed one at a time, from its editor, and
 * only once no Product names it.
 */
class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (Category $record): ?string => $record->note),

                TextColumn::make('slug')
                    ->color('gray'),

                TextColumn::make('theme_key')
                    ->label('Theme')
                    ->badge()
                    ->formatStateUsing(fn (Category $record): string => $record->theme()->getLabel()),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
