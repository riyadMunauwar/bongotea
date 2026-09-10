<?php

namespace App\Filament\Widgets;

use App\Support\Money;
use App\Support\Reporting\ProductRankingRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * Top Products, half width under the Payment Method mix: one row per
 * Product sold anywhere in the Reporting Window, with the units it moved
 * and what they earned, so a Merchant knows what to stock. Highest revenue
 * first, which is the module's own order and the only one this table has —
 * sorting by a clicked figure is the leaderboard's job, not a widget whose
 * whole point is the top of the list.
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

    protected static ?int $sort = 70;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top Products')
            ->records(fn (): Collection => $this->rows())
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
            ->emptyStateDescription('A Product appears here once it is sold on an Order captured inside the Reporting Window.')
            ->paginated(false);
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
        $ranking = app(ReportingQuery::class)->productRanking(ReportingWindow::fromFilters($this->pageFilters));

        return collect($ranking->rows)->map(fn (ProductRankingRow $row): array => [
            ArrayRecord::getKeyName() => $row->product === null ? 'name:'.$row->recordedName : 'product:'.$row->product->getKey(),
            'name' => $row->name(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'units' => $row->units,
            'revenue' => $row->revenue,
        ]);
    }
}
