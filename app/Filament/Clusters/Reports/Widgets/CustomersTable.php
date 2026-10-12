<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Filament\Widgets\TopCustomersTable;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Every Customer who bought in the Reporting Window, most Kilograms first,
 * paged — the whole of the list whose top ten the Dashboard's Top Customers
 * draws, in the same columns. A row opens that Customer's Orders.
 *
 * Named by `CustomerReport` and kept out of the discovered folder, so it
 * stays off the Dashboard.
 */
class CustomersTable extends TableWidget
{
    use InteractsWithPageFilters;
    use PaginatesRows;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    /**
     * A new Reporting Window is a new question, answered from the top.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return TopCustomersTable::customerColumns($table)
            ->heading('Customers')
            ->description('Everybody who bought inside the Reporting Window, by Kilograms Sold, each named by their latest Order in it.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows(
                TopCustomersTable::customerRows(app(ReportingQuery::class)->topCustomers(ReportingWindow::fromFilters($this->pageFilters))->rows),
                $page,
                $recordsPerPage,
            ));
    }
}
