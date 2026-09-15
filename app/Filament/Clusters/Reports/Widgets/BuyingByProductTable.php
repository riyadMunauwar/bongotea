<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Money;
use App\Support\Reporting\BuyingProductRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * What the money bought: one row per Product delivered inside the Reporting
 * Window, with the units that arrived, what they cost and what a unit
 * averaged. Biggest spend first.
 *
 * The same deliveries the Supplier table above counts, read along the other
 * axis — one says where the money went and this says what it bought, and the
 * two always come to the same totals.
 *
 * **The average here is not the Product's cost.** It is what was paid over
 * these days, over these deliveries; the Product's own weighted average is
 * `StockLedger::averageCost()`, blended over every unit the ledger holds
 * (ADR-0022) and stamped onto movements as they happen — which is what the
 * Stock report's Value at Cost is summed from. This one exists so that a
 * Merchant reading two windows side by side can see a supplier's price
 * moving, which is what a Supplier is a record for at all.
 *
 * The counting is `ReportingQuery::buying()`'s and nothing here counts. Lazy,
 * like every report widget, so the shell renders before the query.
 */
class BuyingByProductTable extends TableWidget
{
    use InteractsWithPageFilters;
    use PaginatesRows;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    /**
     * A new Reporting Window is a new question, answered from the top. See
     * `BuyingBySupplierTable` for why a page filter needs this said.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Bought by Product')
            ->description('What arrived inside the Reporting Window and what a unit averaged over it. Not the Product\'s cost, which is blended over everything ever bought — this is what these days paid.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->columns([
                TextColumn::make('product')
                    ->label('Product')
                    ->weight('medium'),

                /** No header, for the reason Top Products' deleted badge has none. */
                TextColumn::make('deleted')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->placeholder(''),

                TextColumn::make('units')
                    ->label('Units bought')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('average_unit_cost')
                    ->label('Average unit cost')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('cost')
                    ->label('Cost')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Nothing bought in this window')
            ->emptyStateDescription('A Product appears here once a delivery naming it is recorded with a date inside the Reporting Window. A Voided delivery never appears.');
    }

    /**
     * The module's Product rows as table rows, in its order — biggest spend
     * first. An average over no units stays null so the column draws its own
     * `—` rather than a nought reading as a Supplier who gave it away.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $buying = app(ReportingQuery::class)->buying(ReportingWindow::fromFilters($this->pageFilters));

        return collect($buying->byProduct)->map(fn (BuyingProductRow $row): array => [
            ArrayRecord::getKeyName() => $row->product->getKey(),
            'product' => $row->name(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'units' => $row->units,
            'average_unit_cost' => $row->averageUnitCost(),
            'cost' => $row->cost,
        ]);
    }
}
