<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\CourierPerformanceTable;
use App\Filament\Concerns\HasReportingWindow;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Which Courier actually delivers, on a screen of its own inside Reports.
 *
 * Built like the Dashboard: the Reporting Window across the top as a filter
 * form, the figures under it, and every figure counted over the same days in
 * the Merchant's own timezone. `HasReportingWindow` supplies the control and
 * the screen state, so a Merchant moving between here and the Dashboard meets
 * one control rather than two that resemble each other, and Filament keeps
 * the chosen window in the session under this class's key.
 *
 * Every figure on it is broken down by Courier and none of them is added up
 * across couriers (ADR-0018): the three speak three vocabularies and agree on
 * almost nothing, so a blended figure would present one courier's silence as
 * the whole trade's. That is the whole reason this is a screen rather than a
 * row of Dashboard tiles.
 *
 * What is *not* here is the courier to-do list — parcels that came back,
 * parcels nobody has heard about. Those ignore the Reporting Window by
 * design and belong on the Dashboard beside Needs Attention; a to-do list
 * drawn under a window a Merchant has narrowed is a backlog that quietly
 * shrinks.
 */
class CourierReport extends Page
{
    use HasFiltersForm;
    use HasReportingWindow;

    /**
     * Reports carries the sidebar entry and the glyph; this page carries
     * neither. `ReportsCluster` holds the reasoning, and the practical effect
     * is that the Product and Order reports after this one cost no icon
     * decision at all.
     */
    protected static ?string $cluster = ReportsCluster::class;

    /**
     * First inside the cluster, which numbers separately from the top-level
     * sidebar — the same way the Settings pages do. The cluster's own sort is
     * what places Reports among the entries a Merchant reads.
     */
    protected static ?int $navigationSort = 0;

    /**
     * Held behind `view reports` through the cluster, the way every Settings
     * page reaches its floor through `SettingsCluster`. The cluster gate and
     * the Dashboard's money widgets were closed together in #161, so there is
     * no figure on this screen a User could read one click away without the
     * Permission that closes it here.
     */
    public static function canAccess(): bool
    {
        return ReportsCluster::canAccess();
    }

    /**
     * The Reporting Window across the top, then the report under it.
     *
     * Written out rather than left to the header-widget slot, because that
     * slot draws above the page content and the control has to come before
     * the figures it governs.
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
     * The Reporting Window control itself. Its components are
     * `HasReportingWindow`'s, so this screen offers the same presets and the
     * same two date pickers the Dashboard does.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([...$this->reportingWindowComponents(), $this->orderKindComponent(), $this->orderCartComponent()]);
    }

    /**
     * The report. Named here rather than discovered, which is also what keeps
     * it off the Dashboard: the panel discovers `App\Filament\Widgets` and
     * draws all of it there, so a windowed courier figure kept in that folder
     * would appear on a screen that is meant to carry none.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            CourierPerformanceTable::class,
        ];
    }
}
