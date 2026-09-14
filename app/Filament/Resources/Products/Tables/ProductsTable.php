<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /** On Hand is summed in the query the list already runs, so a catalogue of any size costs one of them. */
            ->modifyQueryUsing(fn (Builder $query): Builder => app(StockLedger::class)->withOnHand($query))
            ->columns([
                SpatieMediaLibraryImageColumn::make('image')
                    ->collection(Product::IMAGE_COLLECTION)
                    ->label(''),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('price')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable(),

                /**
                 * What this Product holds, on the list itself rather than on
                 * a screen a Merchant has to go and find.
                 *
                 * Nothing where nobody has counted it, never `0` — a zero is
                 * an instruction to reorder, and a Product nobody has looked
                 * at is not a Product that has run out. The figure is the sum
                 * of the Product's movements and is stored in no column
                 * (ADR-0021), which is why it is summed in the query rather
                 * than read off the row.
                 *
                 * Below nothing is allowed and drawn loudly: it means a
                 * parcel was booked against stock nobody recorded arriving,
                 * and an unrecorded Supply announcing itself is the whole
                 * point of letting the figure go negative at all.
                 */
                TextColumn::make(StockLedger::ON_HAND)
                    ->label('On hand')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (?int $state): ?string => $state !== null && $state < 0 ? 'danger' : null)
                    ->weight(fn (?int $state): ?FontWeight => $state !== null && $state < 0 ? FontWeight::Bold : null),

                TextColumn::make('pages_count')
                    ->label('On pages')
                    ->counts('pages'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
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
