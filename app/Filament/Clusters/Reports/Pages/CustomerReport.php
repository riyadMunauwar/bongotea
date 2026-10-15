<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\CustomersTable;
use App\Filament\Concerns\HasReportingWindow;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Who bought: every Customer who bought in the Reporting Window, by Kilograms
 * Sold, each marked New or Returning. The Dashboard's Top Customers is the top
 * ten of this list.
 *
 * It obeys the Reporting Window like every windowed report, and sits behind
 * `view reports` through the cluster.
 */
class CustomerReport extends Page
{
    use HasFiltersForm;
    use HasReportingWindow;

    protected static ?string $cluster = ReportsCluster::class;

    /**
     * Last in Reports, after Visits — see `VisitsReport::$navigationSort`.
     */
    protected static ?int $navigationSort = 5;

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
        return $schema->components([...$this->reportingWindowComponents(), $this->orderKindComponent(), $this->orderCartComponent()]);
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
            CustomersTable::class,
        ];
    }
}
