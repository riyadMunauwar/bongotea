<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Filament\Widgets\Concerns\ScopedToPage;
use App\Support\Money;
use App\Support\Reporting\ProductRankingRow;
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
 * Top Products, half width: one row per Product sold in the Reporting
 * Window, with the units it moved and what they earned, so a Merchant
 * knows what to stock. Highest revenue first, which is the module's own
 * order and the only one this table has — sorting by a clicked figure is
 * the leaderboard's job, not a widget whose whole point is the top of the
 * list.
 *
 * The ranking itself is unbounded — `ReportingQuery::productRanking()`
 * returns a row for every Product sold in the window, which on a real
 * catalogue is hundreds — so the table pages at ten. The first page is the
 * top of the list, which is the whole of what the widget is for; the rest
 * is reachable rather than drawn.
 *
 * It sits under the Payment Method mix on the Dashboard and above it on a
 * Page edit screen, where which Product carries this Page is the first
 * thing asked of the report.
 *
 * A Product deleted since it sold keeps its row under the name its Order
 * Lines recorded, marked deleted while it is only soft-deleted, which is
 * what keeps history whole when a Product is retired.
 *
 * The rows come whole from `ReportingQuery::productRanking()`; nothing here
 * counts or re-orders. Money is drawn through `Money` so the currency
 * Setting is honoured.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class TopProductsTable extends TableWidget
{
    use InteractsWithPageFilters;
    use PaginatesRows;
    use ScopedToPage;

    protected static ?int $sort = 50;

    /**
     * A new Reporting Window is a new question, answered from the top.
     *
     * Filament resets the table page for every filter it owns and not for a
     * page filter, so without this a Merchant narrowing the window from the
     * second page would be left asking for a page the shorter ranking no
     * longer has — an empty table reading as nothing sold.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top Products')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->weight('medium'),

                /**
                 * No header: the badge is the whole message, and a title
                 * over a cell that is empty on every live Product reads as
                 * a missing value.
                 */
                TextColumn::make('deleted')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->placeholder(''),

                TextColumn::make('units')
                    ->label('Units sold')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('revenue')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Nothing sold in this window')
            ->emptyStateDescription('A Product appears here once it is sold on an Order captured inside the Reporting Window.');
    }

    /**
     * The ranking as table rows, keyed for Filament, in the module's order —
     * highest revenue first. The raw figures ride in the row and the columns
     * format them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $ranking = app(ReportingQuery::class)->productRanking(ReportingWindow::fromFilters($this->pageFilters), $this->record);

        return collect($ranking->rows)->map(fn (ProductRankingRow $row): array => [
            ArrayRecord::getKeyName() => $row->product === null ? 'name:'.$row->recordedName : 'product:'.$row->product->getKey(),
            'name' => $row->name(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'units' => $row->units,
            'revenue' => $row->revenue,
        ]);
    }
}
