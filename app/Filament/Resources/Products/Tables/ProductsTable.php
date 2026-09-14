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
            /** Both stock figures are summed into the query the list already runs, so a catalogue of any size costs one of them. */
            ->modifyQueryUsing(function (Builder $query): Builder {
                $ledger = app(StockLedger::class);

                return $ledger->withValueAtCost($ledger->withOnHand($query));
            })
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

                /**
                 * What the units held are worth at what they cost, beside
                 * how many there are: how much capital is sitting on the
                 * shelf, which is the question On Hand alone cannot answer.
                 *
                 * Every movement at the cost stamped on it, summed — the
                 * ledger's own figure, traceable to rows rather than On Hand
                 * multiplied by an average (ADR-0022). Nothing where nothing
                 * bought has ever been recorded: a Product nobody has
                 * supplied has an unknown value, not a free one, which is the
                 * same choice On Hand makes against `0`.
                 */
                TextColumn::make(StockLedger::VALUE_AT_COST)
                    ->label('Value at cost')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::format($state))
                    ->sortable()
                    ->placeholder('—')
                    /** Below nothing for the same reason On Hand is, and said as loudly: money has left that nothing recorded arriving. */
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
