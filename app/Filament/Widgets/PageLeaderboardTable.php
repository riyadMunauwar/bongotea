<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Pages\PageResource;
use App\Support\Money;
use App\Support\Reporting\PageLeaderboardRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * The Page leaderboard, full width under the chart: one row per Page with
 * Orders in the Reporting Window, so a Merchant can set Pages against each
 * other and against their campaigns. Each row carries the Page's title,
 * Orders, Sales, Cancellation Rate, Average Order Value and when it went
 * live, and opens that Page's edit screen; Sales first by default, and
 * any figure sorts. A Visits / Conversion column is reserved so the table
 * does not shift when Visit counting arrives.
 *
 * A Page soft-deleted after taking Orders keeps its row, marked deleted,
 * and still links — the resource serves trashed records. Orders whose
 * Page was deleted for good sit under their recorded slug with nothing
 * to link to.
 *
 * The rows come whole from `ReportingQuery::pageLeaderboard()`, and the
 * table is backed by that result rather than a query: it is re-sorted here
 * as plain data, never re-aggregated. Money is drawn through `Money` so
 * the currency Setting is honoured.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class PageLeaderboardTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Page leaderboard')
            ->records(fn (?string $sortColumn, ?string $sortDirection): Collection => $this->rows($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('title')
                    ->label('Page')
                    ->weight('medium'),

                /**
                 * No header: the badge is the whole message, and a title
                 * over a cell that is empty on every live Page reads as a
                 * missing value.
                 */
                TextColumn::make('deleted')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->placeholder(''),

                TextColumn::make('orders')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('sales')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('cancellation_rate')
                    ->formatStateUsing(fn (float $state): string => number_format($state * 100, 1).'%')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('average_order_value')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('went_live_at')
                    ->label('Went live')
                    ->dateTime()
                    ->placeholder('—'),

                TextColumn::make('visits')
                    ->label('Visits / Conversion')
                    ->state('Not yet counted')
                    ->color('gray')
                    ->tooltip('Visit counting comes in a later release'),
            ])
            ->defaultSort('sales', 'desc')
            ->recordUrl(fn (array $record): ?string => $record['url'])
            ->emptyStateHeading('No Orders in this window')
            ->emptyStateDescription('A Page appears here once an Order is captured on it inside the Reporting Window.')
            ->paginated(false);
    }

    /**
     * The leaderboard as table rows, keyed for Filament and in the order
     * the Merchant asked for. The module's own order — Sales first — is
     * kept until a figure is clicked; the raw figures ride in the row so a
     * sort compares numbers, and the columns format them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(?string $sortColumn, ?string $sortDirection): Collection
    {
        $leaderboard = app(ReportingQuery::class)->pageLeaderboard(ReportingWindow::fromFilters($this->pageFilters));

        $rows = collect($leaderboard->rows)->map(fn (PageLeaderboardRow $row): array => [
            ArrayRecord::getKeyName() => $row->page === null ? 'slug:'.$row->slug : 'page:'.$row->page->getKey(),
            'title' => $row->title(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'orders' => $row->orders,
            'sales' => $row->sales,
            'cancellation_rate' => $row->cancellationRate(),
            'average_order_value' => $row->averageOrderValue(),
            'went_live_at' => $row->wentLiveAt(),
            'url' => $row->page === null ? null : PageResource::getUrl('edit', ['record' => $row->page]),
        ]);

        if ($sortColumn === null) {
            return $rows;
        }

        return $rows
            ->sortBy(fn (array $row): int|float => $row[$sortColumn] ?? 0, descending: $sortDirection === 'desc')
            ->values();
    }
}
