<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Kilograms;
use App\Support\Money;
use App\Support\Reporting\MarginRow;
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
 * Which Products actually made money over the Reporting Window: one row per
 * Product sold in it, with what it earned, what those units were stamped as
 * costing, and what is left. **Biggest margin first**, which is the whole
 * reason this table exists beside Top Products — that one ranks by turnover,
 * and the Product carrying the most turnover and the Product carrying the
 * most profit are not the same Product.
 *
 * Revenue on a row is the Order Lines' own totals and carries **no Delivery
 * Charge**: the charge belongs to an Order rather than to any Product on it,
 * so splitting it across rows would be an invention. It is in the Revenue
 * tile above, where `Sales` is, and the description says so.
 *
 * A Product nothing has been recorded buying draws `—` in both money columns
 * rather than a nought, and sorts below every Product whose margin is known —
 * it has not made nothing, nobody knows what it made (ADR-0022). A Product
 * only *partly* costed keeps its figures and is marked, because a margin
 * missing part of its cost is too high rather than merely imprecise.
 *
 * The counting is `ReportingQuery::margin()`'s and nothing here counts or
 * re-orders. Lazy, like every report widget, so the shell renders before the
 * query.
 */
class MarginByProductTable extends TableWidget
{
    use InteractsWithPageFilters;
    use PaginatesRows;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    /**
     * A new Reporting Window is a new question, answered from the top.
     * Filament resets the table page for every filter it owns and for no page
     * filter, so without this a narrowed window can leave the table asking for
     * a page the shorter list no longer has — an empty table reading as
     * nothing sold.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Earned by Product')
            ->description('What each Product earned inside the Reporting Window and what its units were stamped as costing at Capture. Revenue here is the goods alone — the Delivery Charge belongs to the Order and is counted in the Revenue above.')
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
                    ->label('Kilograms sold')
                    ->formatStateUsing(fn (int|float $state): string => Kilograms::format($state))
                    ->alignEnd(),

                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),

                TextColumn::make('cost')
                    ->label('Cost')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('margin')
                    ->label('Margin')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('margin_rate')
                    ->label('Margin rate')
                    ->formatStateUsing(fn (float $state): string => number_format($state * 100, 1).'%')
                    ->placeholder('—')
                    ->alignEnd(),

                /**
                 * The admission, in its own column rather than in a footnote:
                 * a row whose cost is missing units is drawing a margin that
                 * is too high, and nothing else on the row says so.
                 */
                TextColumn::make('unknown_cost')
                    ->label('')
                    ->badge()
                    ->color('warning')
                    ->placeholder(''),
            ])
            ->emptyStateHeading('Nothing sold in this window')
            ->emptyStateDescription('A Product appears here once it is sold on an Order captured inside the Reporting Window. A Cancelled Order sold nothing, so it is on neither side of this.');
    }

    /**
     * The module's rows as table rows, in its order — biggest margin first,
     * with the Products nobody has priced beneath them. A null stays null so
     * the columns draw their own `—` rather than a nought reading as goods
     * that cost nothing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $margin = app(ReportingQuery::class)->margin(ReportingWindow::fromFilters($this->pageFilters));

        return collect($margin->rows)->map(fn (MarginRow $row): array => [
            ArrayRecord::getKeyName() => $row->product === null ? 'name:'.$row->recordedName : 'product:'.$row->product->getKey(),
            'product' => $row->name(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'units' => $row->units,
            'revenue' => $row->revenue,
            'cost' => $row->cost,
            'margin' => $row->margin(),
            'margin_rate' => $row->marginRate(),
            'unknown_cost' => $row->hasUnknownCost()
                ? ($row->cost === null ? 'no cost recorded' : 'part of the cost is unknown')
                : null,
        ]);
    }
}
