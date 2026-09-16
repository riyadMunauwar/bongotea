<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\StockHoldingsTable;
use App\Filament\Clusters\Reports\Widgets\StockValueStat;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * What the Merchant holds, as things stand this moment.
 *
 * **The one page in Reports that draws no Reporting Window control at all**,
 * and the reason it does not is a rule this cluster recorded before there was
 * anything to apply it to: a report page draws the window only if a figure on
 * it obeys the window, so an all-current page has no control rather than a
 * dead one. Every figure here is a stock level, and a stock level has no span
 * of days behind it — a control above them that changed nothing would be worse
 * than no control, because a Merchant who moved it would read the same figures
 * as the answer to a different question.
 *
 * That is the whole difference between this page and `BuyingReport` beside it,
 * which answers what was bought over a span and therefore carries the control.
 *
 * Behind `view reports` like everything else in this cluster, and this page
 * reaches that floor through `ReportsCluster` the way every Settings page
 * reaches its own through `SettingsCluster`. The cluster was ungated only for
 * as long as the Dashboard drew the same class of figure to every User; #161
 * gated the Dashboard's money widgets in the same breath, so the reading is
 * one working area now. `ReportsCluster` holds that reasoning.
 */
class StockReport extends Page
{
    /**
     * Reports carries the sidebar entry and the glyph; this page carries
     * neither.
     */
    protected static ?string $cluster = ReportsCluster::class;

    /**
     * After the Courier Report, which numbers separately from the top-level
     * sidebar. Reports holds Courier Report 0, Stock 1, Buying 2 — what is
     * carried, then what is held, then what it cost.
     */
    protected static ?int $navigationSort = 1;

    /**
     * Held behind `view reports` through the cluster, the way every Settings
     * page reaches its floor through `SettingsCluster`.
     */
    public static function canAccess(): bool
    {
        return ReportsCluster::canAccess();
    }

    /**
     * The figures, and nothing above them.
     *
     * Written out rather than left to Filament's default for the same reason
     * `CourierReport` writes its own: a report page says on its face what sits
     * above its widgets. What this one says is that nothing does.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(1)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
            ]);
    }

    /**
     * Named here rather than discovered, which is what keeps them off the
     * Dashboard: the panel discovers `App\Filament\Widgets` and draws all of
     * it there.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            StockValueStat::class,
            StockHoldingsTable::class,
        ];
    }
}
