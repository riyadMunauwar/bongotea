<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Support\Money;
use App\Support\Reporting\Margin;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the Reporting Window actually earned: revenue, what the goods cost,
 * and what is left of it.
 *
 * **The revenue tile is `Sales`,** to the minor unit and by construction —
 * both halves are counted at Capture over the Orders `Sales` counts
 * (ADR-0022) — so it says so on its own face, Delivery Charge included, which
 * is the one thing a Merchant reading this beside the Dashboard needs told.
 * The charge is in the figure because `Sales` has always included it; it
 * belongs to an Order rather than to any Product, so it is on no row below.
 *
 * Where nothing sold in the window carries a stamped cost the money tiles say
 * so in words rather than drawing a nought, exactly as `StockValueStat` does
 * for an unpriced shelf: an installation that has recorded no buying has not
 * established that its goods were free, and a zero cost would present the
 * whole of revenue as profit. Where only some units carry one, the figures
 * stand but say how many they are drawn over — the margin is then a ceiling
 * rather than a fact, and the tile is where that is admitted.
 *
 * Nothing here counts; the figures come whole from `ReportingQuery::margin()`
 * and money is drawn through `Money` so the currency Setting is honoured.
 */
class MarginTotalsStat extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 0;

    protected ?string $heading = 'Earned in this window';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $margin = app(ReportingQuery::class)->margin(ReportingWindow::fromFilters($this->pageFilters));

        return [
            Stat::make('Revenue', Money::format($margin->revenue))
                ->description('Sales over this window, Delivery Charge included')
                ->descriptionColor('gray'),

            $this->cost($margin),
            $this->margin($margin),
        ];
    }

    /**
     * What the units sold were stamped as costing at Capture.
     */
    private function cost(Margin $margin): Stat
    {
        if ($margin->cost === null) {
            return Stat::make('Cost of goods', 'Not known')
                ->description('Record a delivery and what sells gets a cost against it')
                ->descriptionColor('gray');
        }

        return Stat::make('Cost of goods', Money::format($margin->cost))
            ->description($this->coverage($margin))
            ->descriptionColor('gray');
    }

    /**
     * What is left of revenue once that cost is off it.
     */
    private function margin(Margin $margin): Stat
    {
        if ($margin->margin() === null) {
            return Stat::make('Margin', 'Not known')
                ->description('Unknown, not the whole of revenue: nothing sold here has a cost stamped on it')
                ->descriptionColor('gray');
        }

        return Stat::make('Margin', Money::format($margin->margin()))
            ->description($this->share($margin))
            ->descriptionColor('gray');
    }

    /**
     * What that margin is as a share of the revenue above it.
     *
     * Where part of the cost is missing the share is drawn as a **ceiling**
     * and said to be one: its numerator is short by whatever those units cost,
     * so the true figure is lower and by an amount nothing on this screen can
     * name. It is not the margin of the costed units — that is a different
     * question over a different set of Orders, and this report does not
     * answer it.
     *
     * A window that earned nothing has no share at all rather than a nought:
     * a rate with no denominator is unknown, and goods given away at a stamped
     * cost lost money rather than breaking even.
     */
    private function share(Margin $margin): string
    {
        $rate = $margin->marginRate();

        if ($rate === null) {
            return 'A share of no revenue is not a share';
        }

        return $margin->everythingCosted()
            ? number_format($rate * 100, 1).'% of revenue'
            : 'At most '.number_format($rate * 100, 1).'% of revenue, with part of the cost unknown';
    }

    /**
     * How much of what sold the cost figure is drawn over. Said only where it
     * is not all of it — a tile that announced completeness every day would
     * stop being read on the day it mattered.
     */
    private function coverage(Margin $margin): string
    {
        if ($margin->everythingCosted()) {
            return 'What the units sold were stamped as costing';
        }

        return number_format($margin->costedUnits).' of '.number_format($margin->units).' units carry a stamped cost';
    }
}
