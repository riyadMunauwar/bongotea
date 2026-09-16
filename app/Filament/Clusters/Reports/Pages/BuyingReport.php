<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\BuyingByProductTable;
use App\Filament\Clusters\Reports\Widgets\BuyingBySupplierTable;
use App\Filament\Clusters\Reports\Widgets\BuyingTotalsStat;
use App\Filament\Concerns\HasReportingWindow;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * What the Merchant bought over a span of days: from whom, how much, and at
 * what cost.
 *
 * It obeys the Reporting Window, unlike `StockReport` beside it, and the
 * distinction is the one `ReportsCluster` records: a report draws the window
 * only where a figure on it obeys one. Buying is money out over a span, and
 * the span is the whole point — it is meant to be read beside `Sales` over the
 * same days, which is only possible while both are cut the same way.
 *
 * A delivery belongs to the window by the day the goods came rather than the
 * day somebody got round to typing it in, because that is the day a buyer
 * looks for. The cut is `ReportingQuery`'s and is made in the Merchant's own
 * timezone.
 *
 * Read twice over: once by Supplier, which says where the money went, and once
 * by Product, which says what it bought. Both are the same deliveries and both
 * come to the totals across the top; a Supplier row opens their own buying
 * history, which is where a surprising figure is answered.
 *
 * Behind `view reports` like everything else in this cluster; `ReportsCluster`
 * holds the reasoning and this page reaches that floor through it.
 * `HasReportingWindow` supplies the control and the screen state, so a Merchant
 * moving between here, the Dashboard and the other reports meets one control
 * rather than four that resemble each other.
 */
class BuyingReport extends Page
{
    use HasFiltersForm;
    use HasReportingWindow;

    /**
     * Reports carries the sidebar entry and the glyph; this page carries
     * neither.
     */
    protected static ?string $cluster = ReportsCluster::class;

    /**
     * Reports holds Courier Report 0, Stock 1, Buying 2 — what is carried,
     * then what is held, then what it cost. The cluster numbers separately
     * from the top-level sidebar.
     */
    protected static ?int $navigationSort = 2;

    /**
     * Held behind `view reports` through the cluster, the way every Settings
     * page reaches its floor through `SettingsCluster`.
     */
    public static function canAccess(): bool
    {
        return ReportsCluster::canAccess();
    }

    /**
     * The Reporting Window across the top, then the report under it.
     *
     * Written out rather than left to the header-widget slot, because that
     * slot draws above the page content and the control has to come before the
     * figures it governs.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedSchema::make('filtersForm'),
                Grid::make(1)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
            ]);
    }

    /**
     * The Reporting Window control itself, from `HasReportingWindow`, so this
     * screen offers the same presets and the same two date pickers the
     * Dashboard does.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components($this->reportingWindowComponents());
    }

    /**
     * Named here rather than discovered, which is what keeps these windowed
     * figures off the Dashboard.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            BuyingTotalsStat::class,
            BuyingBySupplierTable::class,
            BuyingByProductTable::class,
        ];
    }
}
