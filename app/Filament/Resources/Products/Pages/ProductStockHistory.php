<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every movement that ever affected one Product, so that any stock figure on
 * any screen can be explained.
 *
 * Read-only and entirely so: no create, no edit, no delete, no bulk action.
 * Stock is not moved from here — it is moved by counting a shelf, by
 * recording a Supply, by a Courier taking a parcel — and a screen that could
 * write a row would be a second answer to what this Product holds.
 *
 * It reads soft-deleted Products too, which `ProductResource` already arranges
 * for its own routes. A Product taken off the catalogue keeps every movement
 * it ever had: deleting one must not tear a hole in a past report, and the
 * screen that would explain that report is this one.
 */
class ProductStockHistory extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'stockMovements';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $title = 'Stock history';

    /** A box on a shelf, the glyph Inventory carries in the sidebar. */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    /**
     * What this Product holds and what it is worth, above the movements both
     * figures are the sum of.
     *
     * Nothing where nobody has counted it and nothing where nobody has bought
     * it, never `0`: a zero is an instruction to reorder, and a Product
     * nobody has looked at is not a Product that has run out — nor is one
     * nobody has supplied a Product that was free (ADR-0022).
     */
    public function getSubheading(): string
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $ledger = app(StockLedger::class);
        $value = $ledger->valueAtCost($product);

        return 'On hand: '.($ledger->onHand($product) ?? '—')
            .' · Value at cost: '.($value === null ? '—' : Money::format($value));
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing has moved yet')
            ->emptyStateDescription('A Product enters the ledger when it is first counted or first supplied, and not before.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->badge(),

                /** Signed, because which way it went is the whole of what a movement says. */
                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.$state : (string) $state)
                    ->color(fn (int $state): string => match (true) {
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    }),

                /**
                 * What a unit was worth as it moved, stamped at the time and
                 * never recomputed (ADR-0022). Nothing where no average was
                 * known — a Product nobody has supplied must not read as
                 * free.
                 */
                TextColumn::make('unit_cost')
                    ->label('Unit cost')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::format($state))
                    ->placeholder('—'),

                /**
                 * What the movement was worth altogether: its units at the
                 * cost stamped on it, signed the way the quantity is.
                 *
                 * The column a loss is read off. A parcel given up as dead
                 * writes a Loss here, and this is where "what did that
                 * actually cost me" is answered in money rather than left as
                 * a quantity a Merchant has to multiply — which is the whole
                 * difference between a written-off parcel and shrinkage
                 * nobody can account for.
                 *
                 * Nothing where no cost was stamped, for the reason the unit
                 * cost beside it reads nothing: a movement from before
                 * anything was bought is worth an unknown amount, never zero.
                 */
                TextColumn::make('value')
                    ->label('Value')
                    ->state(fn (StockMovement $record): ?int => $record->unit_cost === null
                        ? null
                        : $record->quantity * $record->unit_cost)
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::format($state))
                    ->color(fn (?int $state): string => match (true) {
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('—'),
            ]);
    }

    /**
     * Nothing writes from here, so nothing is offered from here.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
