<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasReportingWindow;
use App\Filament\Resources\Shipments\Widgets\CourierPerformanceTable;
use BackedEnum;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Which Courier actually delivers, on a screen of its own.
 *
 * It answers of the couriers what the Page Report answers of one Page, and is
 * built the same way: the Reporting Window across the top as a filter form,
 * the figures under it, and every figure counted over the same days in the
 * Merchant's own timezone. `HasReportingWindow` supplies the control and the
 * screen state, so a Merchant moving between here, the Dashboard and a Page
 * Report meets one control rather than three that resemble each other, and
 * Filament keeps the chosen window in the session under this class's key.
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
     * A chart, because this screen is figures about something rather than a
     * list of it. The truck belongs to Shipments, which is the list, and two
     * entries a line apart drawn with one glyph are two entries a Merchant
     * cannot tell apart in the collapsed rail.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /**
     * Under Shipments, which is what it reports on — the sidebar reads
     * Dashboard, Orders, Shipments, Courier Report, Products, Pages, then
     * Settings at 100. Every top-level entry states its own sort, so
     * inserting one here renumbers the two below it on purpose; Filament
     * reads an undeclared sort as `-1`, which is above the Dashboard rather
     * than at the end.
     */
    protected static ?int $navigationSort = 3;

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
        return $schema->components($this->reportingWindowComponents());
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
