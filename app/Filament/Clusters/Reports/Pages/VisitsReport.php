<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\VisitsByPlaceTable;
use App\Filament\Concerns\HasReportingWindow;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Where the Visits went: every place of the store that drew one in the
 * Reporting Window, with each Product's Buy Rate beside its Visits. The
 * Dashboard's Most visited is the top ten of this list.
 *
 * It obeys the Reporting Window like every windowed report, and sits behind
 * `view reports` through the cluster. `HasReportingWindow` supplies the
 * control, so it is the same one the Dashboard and the other reports draw.
 */
class VisitsReport extends Page
{
    use HasFiltersForm;
    use HasReportingWindow;

    protected static ?string $cluster = ReportsCluster::class;

    /**
     * After Margin: Reports holds Courier 0, Stock 1, Buying 2, Margin 3,
     * Visits 4, Customers 5 — the stock and money, then who came and who
     * bought.
     */
    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return ReportsCluster::canAccess();
    }

    /**
     * The Reporting Window across the top, then the report under it, for the
     * reason `MarginReport::content()` gives.
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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components($this->reportingWindowComponents());
    }

    /**
     * Named here rather than discovered, which is what keeps it off the
     * Dashboard.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            VisitsByPlaceTable::class,
        ];
    }
}
