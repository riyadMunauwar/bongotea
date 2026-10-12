<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Orders\OrderResource;
use App\Support\Kilograms;
use App\Support\Money;
use App\Support\Reporting\CustomerRankingRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * Top Customers, half width beside Most visited: the ten Customers who bought
 * the most Kilograms in the Reporting Window, each by phone and the name on
 * their latest Order, marked New or Returning. A row opens that Customer's
 * Orders.
 *
 * Ten and unpaged, like Most visited; the whole list is the Customer
 * Report's. The rows come whole from `ReportingQuery::topCustomers()`; nothing
 * here counts. Lazy, like every report widget.
 */
class TopCustomersTable extends TableWidget implements GatedOnDashboard
{
    use InteractsWithPageFilters;

    /**
     * How many Customers the Dashboard names.
     */
    public const int LISTED = 10;

    protected static ?int $sort = 54;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`.
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    public function table(Table $table): Table
    {
        return self::customerColumns($table)
            ->heading('Top Customers')
            ->description('The top '.self::LISTED.' by Kilograms Sold. Every Customer is on the Customer Report.')
            ->records(fn (): Collection => self::customerRows(
                app(ReportingQuery::class)->topCustomers(ReportingWindow::fromFilters($this->pageFilters))->rows,
            )->take(self::LISTED))
            ->paginated(false);
    }

    /**
     * The columns and the row link, shared with the Customer Report so a
     * Customer reads the same on both.
     */
    public static function customerColumns(Table $table): Table
    {
        return $table
            ->recordUrl(fn (array $record): ?string => OrderResource::canAccess() ? OrderResource::byPhoneUrl($record['phone']) : null)
            ->columns([
                TextColumn::make('phone')
                    ->weight('medium'),

                TextColumn::make('name')
                    ->placeholder('—'),

                TextColumn::make('kind')
                    ->label('')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Returning' ? 'primary' : 'gray'),

                TextColumn::make('orders')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('kilograms')
                    ->label('Kilograms Sold')
                    ->formatStateUsing(fn (int $state): string => Kilograms::format($state))
                    ->alignEnd(),

                TextColumn::make('sales')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Nobody bought in this window')
            ->emptyStateDescription('A Customer appears here once an Order of theirs that is not Cancelled is captured inside the Reporting Window.');
    }

    /**
     * @param  list<CustomerRankingRow>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function customerRows(array $rows): Collection
    {
        return collect($rows)->map(fn (CustomerRankingRow $row): array => [
            ArrayRecord::getKeyName() => 'customer:'.$row->customer->getKey(),
            'phone' => $row->customer->phone,
            'name' => $row->latestName,
            'kind' => $row->isReturning ? 'Returning' : 'New',
            'orders' => $row->orders,
            'kilograms' => $row->kilograms,
            'sales' => $row->sales,
        ]);
    }
}
