<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\MarginByProductTable;
use App\Filament\Clusters\Reports\Widgets\MarginTotalsStat;
use App\Filament\Concerns\HasReportingWindow;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * What the Merchant earned over a span of days rather than what they turned
 * over: the figure the whole cost side of the ledger exists for.
 *
 * It is the last of the three inventory reports and the one that joins the
 * other two to the selling side. Stock says what is held, Buying says what it
 * cost to get, and this says what selling it made — read per Product, so a
 * Merchant scales spend on the Product carrying the profit rather than on the
 * one carrying the turnover, which `Top Products` on the Dashboard cannot
 * tell apart.
 *
 * It obeys the Reporting Window, like the Buying and Courier reports beside
 * it, and unlike `StockReport` — the distinction `ReportsCluster` records.
 * Here the window governs more than usual: **the revenue on this page is
 * `Sales` over the same window, to the minor unit**. Both halves are counted
 * at Capture over the Orders `Sales` counts, so a Merchant can read this
 * beside the Dashboard and never meet two revenue figures for one span of
 * days (ADR-0022). The Delivery Charge is inside that revenue because `Sales`
 * has always included it, and the Revenue tile says so where it is drawn.
 *
 * Ungated, like everything else in this cluster; `ReportsCluster` holds the
 * reasoning. `HasReportingWindow` supplies the control and the screen state,
 * so a Merchant moving between here, the Dashboard and the other reports meets
 * one control rather than four that resemble each other.
 */
class MarginReport extends Page
{
    use HasFiltersForm;
    use HasReportingWindow;

    /**
     * Reports carries the sidebar entry and the glyph; this page carries
     * neither.
     */
    protected static ?string $cluster = ReportsCluster::class;

    /**
     * Reports holds Courier Report 0, Stock 1, Buying 2, Margin 3 — what is
     * carried, then what is held, then what it cost, then what it made. The
     * cluster numbers separately from the top-level sidebar.
     */
    protected static ?int $navigationSort = 3;

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
            MarginTotalsStat::class,
            MarginByProductTable::class,
        ];
    }
}
