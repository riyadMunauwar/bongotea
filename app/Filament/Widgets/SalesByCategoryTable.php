<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Widgets\Concerns\DescribesChange;
use App\Support\Kilograms;
use App\Support\Money;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SalesByCategory;
use App\Support\Reporting\SalesByCategoryRow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * Sales by Category, half width beside Top Products, which it sums up one
 * level higher: each Category's Orders, Sales and Kilograms Sold in the
 * Reporting Window, its share of the Kilograms, and how its Kilograms moved
 * against the comparison window.
 *
 * A handful of rows, so a table rather than a chart, and unpaged. A
 * Category's money is its lines alone, so the Delivery Charges are named
 * under the heading rather than split among the rows.
 *
 * The rows come whole from `ReportingQuery::salesByCategory()`; nothing here
 * counts. Lazy, like every report widget.
 */
class SalesByCategoryTable extends TableWidget implements GatedOnDashboard
{
    use DescribesChange;
    use InteractsWithPageFilters;

    protected static ?int $sort = 52;

    private ?SalesByCategory $sales = null;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`.
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sales by Category')
            ->description(fn (): ?string => $this->salesByCategory()->rows === []
                ? null
                : 'Delivery Charges ('.Money::format($this->salesByCategory()->deliveryCharge).') are counted in Sales but belong to no Category.')
            ->records(fn (): Collection => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Category')
                    ->weight('medium'),

                TextColumn::make('orders')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('sales')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),

                TextColumn::make('kilograms')
                    ->label('Kilograms Sold')
                    ->formatStateUsing(fn (int $state): string => Kilograms::format($state))
                    ->alignEnd(),

                TextColumn::make('share')
                    ->label('Share of kg')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('change')
                    ->label('Kilograms change')
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Nothing sold in this window')
            ->emptyStateDescription('A Category appears here once a Product sold under it is on an Order captured inside the Reporting Window.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $sales = $this->salesByCategory();

        return collect($sales->rows)->map(fn (SalesByCategoryRow $row): array => [
            ArrayRecord::getKeyName() => 'category:'.($row->category?->getKey() ?? 'deleted'),
            'name' => $row->name(),
            'orders' => $row->orders,
            'sales' => $row->sales,
            'kilograms' => $row->kilograms,
            'share' => $this->percent($row->kilogramsShareOf($sales->kilograms())),
            'change' => $this->change($row->kilograms, $row->previousKilograms),
        ]);
    }

    private function salesByCategory(): SalesByCategory
    {
        return $this->sales ??= app(ReportingQuery::class)->salesByCategory(ReportingWindow::fromFilters($this->pageFilters));
    }
}
