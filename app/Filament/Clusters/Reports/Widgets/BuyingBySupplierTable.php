<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Inventory\Grams;
use App\Support\Money;
use App\Support\Reporting\BuyingSupplierRow;
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
 * Where the money went: one row per Supplier who delivered inside the
 * Reporting Window, with how many deliveries they made, how many units came
 * and what they cost. Biggest spend first.
 *
 * Every row opens that Supplier's own buying history — the Supplies list with
 * their filter already on — so the question this table raises ("why is this
 * one so large?") is answered one click away, on the documents themselves
 * rather than on another aggregate. That list carries their voided deliveries
 * too, because the User who follows a surprising figure is exactly the one
 * looking for what went wrong.
 *
 * A Supplier taken off the list keeps their row and says so. What they
 * delivered inside the window is a fact about the Merchant's money, not about
 * whether anybody still buys from them.
 *
 * The counting is `ReportingQuery::buying()`'s and nothing here counts. Lazy,
 * like every report widget, so the shell renders before the query.
 */
class BuyingBySupplierTable extends TableWidget
{
    use InteractsWithPageFilters;
    use PaginatesRows;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    /**
     * A new Reporting Window is a new question, answered from the top.
     *
     * Filament resets the table page for every filter it owns and not for a
     * page filter, so without this a Merchant narrowing the window from the
     * second page would be left asking for a page the shorter result no longer
     * has — an empty table reading as nothing bought.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Bought by Supplier')
            ->description('Who the goods came from inside the Reporting Window, and what they charged. Open a row for that Supplier\'s own deliveries.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->recordUrl(fn (array $record): string => $record['url'])
            ->columns([
                TextColumn::make('supplier')
                    ->label('Supplier')
                    ->weight('medium'),

                /** No header, for the reason Top Products' deleted badge has none: a title over a cell empty on every live row reads as a missing value. */
                TextColumn::make('deleted')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->placeholder(''),

                TextColumn::make('deliveries')
                    ->label('Deliveries')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('units')
                    ->label('Units')
                    ->formatStateUsing(fn (int $state): string => Grams::toKilograms($state))
                    ->alignEnd(),

                TextColumn::make('cost')
                    ->label('Cost')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Nothing bought in this window')
            ->emptyStateDescription('A Supplier appears here once a delivery of theirs is recorded with a date inside the Reporting Window. A Voided delivery never appears.');
    }

    /**
     * The module's Supplier rows as table rows, in its order — biggest spend
     * first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $buying = app(ReportingQuery::class)->buying(ReportingWindow::fromFilters($this->pageFilters));

        return collect($buying->bySupplier)->map(fn (BuyingSupplierRow $row): array => [
            ArrayRecord::getKeyName() => $row->supplier->getKey(),
            'supplier' => $row->name(),
            'deleted' => $row->isDeleted() ? 'deleted' : null,
            'deliveries' => $row->deliveries,
            'units' => $row->units,
            'cost' => $row->cost,
            'url' => SupplyResource::deliveriesFromUrl($row->supplier),
        ]);
    }
}
