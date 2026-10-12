<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Filament\Widgets\Concerns\DescribesChange;
use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\VisitsByPlace;
use App\Support\Reporting\VisitsByPlaceRow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every place of the store that drew a Visit in the Reporting Window, most
 * visited first: its Visits, its share of the store's, its change against the
 * comparison window — and for a Product, the Orders that held it and its Buy
 * Rate, which is what tells a Product looked at a lot and rarely bought.
 *
 * The places are a breakdown of the store's Visits, so the heading says how
 * many of those no place accounts for rather than leaving the rows to look
 * like the whole.
 *
 * The rows come whole from `ReportingQuery::visitsByPlace()`; nothing here
 * counts. Named by `VisitsReport` and kept out of the discovered folder, so it
 * stays off the Dashboard.
 */
class VisitsByPlaceTable extends TableWidget
{
    use DescribesChange;
    use InteractsWithPageFilters;
    use PaginatesRows;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    private ?VisitsByPlace $places = null;

    /**
     * A new Reporting Window is a new question, answered from the top.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Visits by place')
            ->description(fn (): ?string => $this->unmatched())
            ->records(fn (int|string $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection): LengthAwarePaginator => $this->paginateRows(
                $this->sorted($this->rows(), $sortColumn, $sortDirection),
                $page,
                $recordsPerPage,
            ))
            ->columns([
                TextColumn::make('name')
                    ->label('Place')
                    ->weight('medium'),

                TextColumn::make('kind')
                    ->label('')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('visits')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('share')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('change')
                    ->alignEnd(),

                TextColumn::make('orders')
                    ->label('Orders holding it')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('buy_rate')
                    ->label('Buy Rate')
                    ->formatStateUsing(fn (float $state): string => (string) $this->percent($state))
                    ->sortable()
                    ->alignEnd()
                    ->placeholder('—'),
            ])
            ->emptyStateHeading(fn (): string => $this->visitsByPlace()->isCounted() ? 'No Visits in this window' : 'Visits are not counted')
            ->emptyStateDescription(fn (): string => $this->visitsByPlace()->isCounted()
                ? 'A place appears here once a page of the store is loaded inside the Reporting Window.'
                : 'Switch Visit counting on in Settings → Tracking to see where Visits go.');
    }

    /**
     * The store's Visits no place accounts for, as one line under the
     * heading; null where there are none, or nothing is counted.
     */
    private function unmatched(): ?string
    {
        $unmatched = $this->visitsByPlace()->unmatched();

        if (! $unmatched) {
            return null;
        }

        return 'Not matched to a place: '.number_format($unmatched).' of '.number_format((int) $this->visitsByPlace()->visits)
            .' Visits — loads of an address that is no Page, Category or Product, and days counted before places were.';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $places = $this->visitsByPlace();

        return collect($places->rows)->map(fn (VisitsByPlaceRow $row): array => [
            ArrayRecord::getKeyName() => $row->key(),
            'name' => $row->name(),
            'kind' => $row->kind->getLabel(),
            'visits' => $row->visits,
            'share' => $this->percent($row->shareOf((int) $places->visits)),
            'change' => $this->change($row->visits, $row->previousVisits),
            'orders' => $row->orders,
            'buy_rate' => $row->buyRate(),
        ]);
    }

    /**
     * The whole list in the order a clicked column asks for, before the page
     * is cut from it; the module's own order — most visited first — where
     * none is asked for. A place with no figure in the column (no Orders, no
     * Buy Rate) sorts below every place that has one, whichever way.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sorted(Collection $rows, ?string $column, ?string $direction): Collection
    {
        if (! in_array($column, ['visits', 'orders', 'buy_rate'], true)) {
            return $rows;
        }

        $descending = $direction === 'desc';

        return $rows
            ->sort(function (array $a, array $b) use ($column, $descending): int {
                if (($a[$column] === null) !== ($b[$column] === null)) {
                    return $a[$column] === null ? 1 : -1;
                }

                return $descending ? $b[$column] <=> $a[$column] : $a[$column] <=> $b[$column];
            })
            ->values();
    }

    private function visitsByPlace(): VisitsByPlace
    {
        return $this->places ??= app(ReportingQuery::class)->visitsByPlace(ReportingWindow::fromFilters($this->pageFilters));
    }
}
