<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Inventory\Grams;
use App\Support\Money;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\StockHoldingRow;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Stock report itself: one row per Product with what it holds and what
 * those units are worth, the most capital first.
 *
 * **It reads no Reporting Window and the screen draws none.** Every figure
 * here is as things stand this moment, so there is no span of days to narrow
 * and no filter to obey — which is why this widget, alone among the report
 * widgets, reads no page filters and needs no `updatedPageFilters()`.
 *
 * Both figures draw `—` where the ledger answers unknown, never `0`. A zero
 * is an instruction to reorder and a blank in a money column is read as free
 * stock; a Product nobody has counted is neither. Below nothing is drawn
 * loudly on both, because money or units leaving that nothing recorded
 * arriving is an unrecorded Supply announcing itself — the same drawing the
 * Products list gives the same two figures.
 *
 * The counting is `ReportingQuery::stockHoldings()`'s and nothing here counts.
 * Lazy, like every report widget, so the shell renders before the query.
 */
class StockHoldingsTable extends TableWidget
{
    use PaginatesRows;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Stock held')
            ->description('What every Product holds right now and what those units cost. As things stand this moment — there is no Reporting Window on this screen, because a stock level has no span of days behind it.')

            /**
             * A row per Product, which on a real catalogue is hundreds, so the
             * table pages at ten. The first page is where the capital is.
             */
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->weight('medium'),

                /**
                 * No header: the badge is the whole message, and a title over
                 * a cell that is empty on every live Product reads as a
                 * missing value. The same drawing Top Products gives it.
                 */
                TextColumn::make('deleted')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->placeholder(''),

                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Grams::toKilograms($state))
                    ->placeholder('—')
                    ->color(fn (array $record): ?string => self::belowNothing($record['on_hand']) ? 'danger' : null)
                    ->weight(fn (array $record): ?FontWeight => self::belowNothing($record['on_hand']) ? FontWeight::Bold : null)
                    ->alignEnd(),

                TextColumn::make('value_at_cost')
                    ->label('Value at cost')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->placeholder('—')
                    ->color(fn (array $record): ?string => self::belowNothing($record['value_at_cost']) ? 'danger' : null)
                    ->weight(fn (array $record): ?FontWeight => self::belowNothing($record['value_at_cost']) ? FontWeight::Bold : null)
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Nothing in the catalogue yet')
            ->emptyStateDescription('A Product appears here as soon as it is added. What it holds appears once somebody counts it or records a delivery of it.');
    }

    /**
     * The holdings as table rows, keyed by Product, in the module's order —
     * the most capital first, and the Products nobody has priced beneath them.
     *
     * An unknown stays null so the column draws its own `—` rather than a
     * nought a Merchant would read as an answer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $holdings = app(ReportingQuery::class)->stockHoldings();

        return collect($holdings->rows)->map(fn (StockHoldingRow $row): array => [
            ArrayRecord::getKeyName() => $row->product->getKey(),
            'name' => $row->name(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'on_hand' => $row->onHand,
            'value_at_cost' => $row->valueAtCost,
        ]);
    }

    /**
     * Whether a figure has gone below nothing, which an unknown never has.
     */
    private static function belowNothing(?int $figure): bool
    {
        return $figure !== null && $figure < 0;
    }
}
