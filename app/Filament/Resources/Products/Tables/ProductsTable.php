<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Support\Inventory\CommittedStock;
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
            /** Every stock figure is summed into the query the list already runs, so a catalogue of any size costs one of them. */
            ->modifyQueryUsing(function (Builder $query): Builder {
                $ledger = app(StockLedger::class);

                return app(CommittedStock::class)->withCommitted(
                    $ledger->withValueAtCost($ledger->withOnHand($query)),
                );
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
                 * What is already spoken for, beside what is held: the units
                 * on Confirmed Orders nobody has sent yet.
                 *
                 * A fact about Orders and never a Stock Movement (ADR-0021).
                 * Nothing has happened to a box, so it is drawn next to On
                 * Hand and never taken off it — a Merchant reads the pair and
                 * sees what is on the shelf and how much of it is already
                 * promised.
                 *
                 * Zero rather than nothing where nothing is waiting, which is
                 * the one place this column parts company with On Hand beside
                 * it: this is read off the Orders themselves and so is never
                 * unknown, and a blank drawn for a figure we do know would be
                 * the mirror of the mistake the `—` above exists to avoid.
                 */
                TextColumn::make(CommittedStock::COMMITTED)
                    ->label('Committed')
                    ->numeric()
                    ->sortable()
                    ->default(0),

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
