<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Inventory\CommittedStock;
use App\Support\Inventory\DaysOfCover;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        /** Resolved once for the whole table rather than inside the closures a column runs per row. */
        $cover = app(DaysOfCover::class);

        return $table
            /** Every stock figure is summed into the query the list already runs, so a catalogue of any size costs one of them. */
            ->modifyQueryUsing(function (Builder $query) use ($cover): Builder {
                $ledger = app(StockLedger::class);
                $query->with(['category', 'brand']);

                return $cover->withUnitsShipped(
                    app(CommittedStock::class)->withCommitted(
                        $ledger->withValueAtCost($ledger->withOnHand($query)),
                    ),
                );
            })
            ->columns([
                /** The cover: the gallery's first image, as a card draws it. */
                SpatieMediaLibraryImageColumn::make('gallery')
                    ->collection(Product::GALLERY_COLLECTION)
                    ->limit(1)
                    ->label(''),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record): string => "{$record->category->name} · {$record->brand->name}"),

                TextColumn::make('status')
                    ->badge(),

                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean(),

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
                 * How long what is held lasts at the rate it is going out,
                 * beside how much of it there is: the figure a Merchant acts
                 * on, because "fourteen left" means nothing without knowing
                 * that the supplier takes a week.
                 *
                 * Nothing where there is no cover to report, and for either of
                 * two reasons: nobody has counted the shelf, or nothing has
                 * gone out of it over the span the rate is read across. Never
                 * `0` and never for ever — a dormant Product presenting itself
                 * as infinitely safe is the reading this exists to prevent
                 * (ADR-0021).
                 *
                 * Deliberately not sortable. Both halves are summed subqueries
                 * and the figure is the one divided by the other, so an ORDER
                 * BY would mean spelling both out again in raw SQL, dividing
                 * by a nought that is legal on one driver and fatal on
                 * another, and keeping that arithmetic in step with
                 * `DaysOfCover::of()` for ever. What the thin shelves are is a
                 * question Needs Attention answers, in order, on the screen a
                 * Merchant opens first.
                 */
                TextColumn::make(DaysOfCover::COVER)
                    ->label('Days of cover')
                    ->numeric()
                    ->state(fn (Product $record): ?float => $cover->read($record))
                    ->formatStateUsing(fn (?float $state): ?string => $state === null ? null : number_format($state, 1))
                    ->placeholder('—')
                    /** Loud under the Merchant's own threshold, asked of the module rather than compared here, so this and Needs Attention draw one line. */
                    ->color(fn (?float $state): ?string => $cover->isThin($state) ? 'danger' : null)
                    ->weight(fn (?float $state): ?FontWeight => $cover->isThin($state) ? FontWeight::Bold : null),

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

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(ProductStatus::class),
                TernaryFilter::make('is_available')
                    ->label('Available'),
                /** Taken out of the catalogue is the soft delete; this is how to find those again. */
                TrashedFilter::make()
                    ->label('Taken out of the catalogue'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                /** Taken out and put back, never force-deleted, for the reason `EditProduct` gives. */
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Take out of catalogue'),
                    RestoreBulkAction::make()
                        ->label('Put back in catalogue'),
                ]),
            ]);
    }
}
