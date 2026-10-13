<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Clusters\Settings\Pages\Tracking;
use App\Support\Kilograms;
use App\Support\Money;
use App\Support\Reporting\Overview;
use App\Support\Reporting\OverviewFigures;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Closure;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The row of stat tiles under the Reporting Window: Orders and Sales side
 * by side, never collapsed into one, and Kilograms Sold beside Sales, the
 * same Orders weighed rather than totalled; Average Order Value and the
 * Cancellation Rate; Unpaid and New awaiting call, the two queues; New and
 * Returning Customers, each counted over the window whole; and
 * Visits, which is a figure where the Merchant has switched Visit counting
 * on and a sentence pointing at that switch where they have not, with the
 * store-wide Conversion Rate beside it, which is unknown while Visits are.
 *
 * Each tile reads its change against the comparison window, coloured by
 * whether up is good — it is not for the Cancellation Rate or Unpaid — and
 * carries the window's buckets as a sparkline. The figures come whole from
 * `ReportingQuery`; nothing here counts, and money is drawn through
 * `Money` so the currency Setting is honoured.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class OverviewStats extends StatsOverviewWidget implements GatedOnDashboard
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 10;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`
     * (#161).
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    protected ?string $heading = 'Overview';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $overview = app(ReportingQuery::class)->overview(ReportingWindow::fromFilters($this->pageFilters));

        $current = $overview->figures;
        $previous = $overview->comparison;

        return [
            $this->tile('Orders', number_format($current->orders), $overview, fn (OverviewFigures $figures): int => $figures->orders)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->orders, $previous->orders, upIsGood: true)),

            $this->tile('Sales', Money::format($current->sales), $overview, fn (OverviewFigures $figures): int => $figures->sales)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->sales, $previous->sales, upIsGood: true)),

            $this->tile('Kilograms Sold', Kilograms::format($current->kilograms), $overview, fn (OverviewFigures $figures): int|float => $figures->kilograms)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->kilograms, $previous->kilograms, upIsGood: true)),

            $this->tile('Average Order Value', Money::format($current->averageOrderValue()), $overview, fn (OverviewFigures $figures): int => $figures->averageOrderValue())
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->averageOrderValue(), $previous->averageOrderValue(), upIsGood: true)),

            $this->tile('Cancellation Rate', $this->percent($current->cancellationRate()), $overview, fn (OverviewFigures $figures): float => round($figures->cancellationRate() * 100, 1))
                ->tap(fn (Stat $stat) => $this->describeRateChange($stat, $overview, $current->cancellationRate(), $previous->cancellationRate())),

            $this->tile('Unpaid', number_format($current->unpaid), $overview, fn (OverviewFigures $figures): int => $figures->unpaid)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->unpaid, $previous->unpaid, upIsGood: false)),

            $this->tile('New awaiting call', number_format($current->new), $overview, fn (OverviewFigures $figures): int => $figures->new)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->new, $previous->new, upIsGood: true)),

            $this->tile('New Customers', number_format($current->newCustomers), $overview, fn (OverviewFigures $figures): int => $figures->newCustomers)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->newCustomers, $previous->newCustomers, upIsGood: true)),

            $this->returningCustomers($overview),

            $this->visits($overview),
            $this->conversionRate($overview),
        ];
    }

    /**
     * The Returning Customers tile, whose description leads with their share
     * of everybody who bought in the window — the figure wholesale lives on —
     * before the change against the comparison window.
     */
    private function returningCustomers(Overview $overview): Stat
    {
        $current = $overview->figures;
        $buyers = $current->newCustomers + $current->returningCustomers;

        $stat = $this->tile('Returning Customers', number_format($current->returningCustomers), $overview, fn (OverviewFigures $figures): int => $figures->returningCustomers);
        $this->describeChange($stat, $overview, $current->returningCustomers, $overview->comparison->returningCustomers, upIsGood: true);

        if ($buyers === 0) {
            return $stat;
        }

        return $stat->description($this->percent($current->returningCustomers / $buyers).' of buyers · '.$stat->getDescription());
    }

    /**
     * The Conversion Rate tile: Orders over Visits for the whole store, or
     * "Not counted" where Visits are off — a rate with no denominator is
     * unknown, and a 0.0% would read as a store that sells nothing. No
     * sparkline, for the reason the Visits tile has none.
     */
    private function conversionRate(Overview $overview): Stat
    {
        $rate = $overview->figures->conversionRate();

        if ($rate === null) {
            return Stat::make('Conversion Rate', 'Not counted')
                ->description('Counted once Visits are')
                ->descriptionColor('gray');
        }

        $previous = $overview->comparison->conversionRate();

        /** A comparison nobody counted is unknown, so no change is measured against it rather than against a nought. */
        return Stat::make('Conversion Rate', $this->percent($rate))
            ->tap(fn (Stat $stat) => $previous === null ? null : $this->describeRateChange($stat, $overview, $rate, $previous, upIsGood: true));
    }

    /**
     * The Visits tile: loads of the store in the window,
     * or the sentence that says counting is off and where to switch it on.
     *
     * Off is said rather than drawn as a nought, because a zero on a tile
     * beside six tiles carrying figures reads as no traffic — which is a
     * Merchant's worst afternoon, and would be a lie. The tile links to the
     * screen that switches it on, so being told is also being taken there.
     *
     * It carries no sparkline, alone among the tiles. The aggregate behind it
     * counts a day, and a one-day window's buckets are hours; a line drawn
     * over those would have to invent five figures out of one.
     */
    private function visits(Overview $overview): Stat
    {
        $visits = $overview->figures->visits;

        if ($visits === null) {
            /** The link only where the User could act on it; the sentence stands either way. */
            $reachable = Tracking::canAccess();

            return Stat::make('Visits', 'Not counted')
                ->description('Switch Visit counting on in Settings → Tracking')
                ->descriptionIcon($reachable ? Heroicon::ArrowTopRightOnSquare : null)
                ->descriptionColor('gray')
                ->url($reachable ? Tracking::getUrl() : null);
        }

        return Stat::make('Visits', number_format($visits))
            ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $visits, (int) $overview->comparison->visits, upIsGood: true));
    }

    /**
     * A tile with its sparkline: one figure per bucket of the window.
     *
     * @param  Closure(OverviewFigures): (int|float)  $figure
     */
    private function tile(string $label, string $value, Overview $overview, Closure $figure): Stat
    {
        return Stat::make($label, $value)->chart($overview->series($figure));
    }

    /**
     * The change in a count or an amount against the comparison window, as
     * a percentage: "12% up on the previous 7 days". A window whose
     * comparison had nothing reads "up from none", since there is no
     * percentage of nothing; two empty windows read as no change.
     */
    private function describeChange(Stat $stat, Overview $overview, int|float $current, int|float $previous, bool $upIsGood): void
    {
        $against = $this->comparisonName($overview);

        if ($current == $previous) {
            $this->describe($stat, "No change on {$against}", 0, $upIsGood);

            return;
        }

        if ($previous == 0) {
            $this->describe($stat, 'Up from none', 1, $upIsGood);

            return;
        }

        $change = ($current - $previous) / $previous * 100;
        $direction = $change > 0 ? 'up' : 'down';

        $this->describe($stat, number_format(abs($change)).'% '.$direction." on {$against}", $change <=> 0, $upIsGood);
    }

    /**
     * The change in a rate, in percentage points rather than a percentage
     * of a percentage: "3.2 pts up on the previous 7 days". Up is bad for
     * the Cancellation Rate and good for the Conversion Rate.
     */
    private function describeRateChange(Stat $stat, Overview $overview, float $current, float $previous, bool $upIsGood = false): void
    {
        $against = $this->comparisonName($overview);
        $points = round(($current - $previous) * 100, 1);

        if ($points === 0.0) {
            $this->describe($stat, "No change on {$against}", 0, $upIsGood);

            return;
        }

        $direction = $points > 0 ? 'up' : 'down';

        $this->describe($stat, number_format(abs($points), 1).' pts '.$direction." on {$against}", $points <=> 0, $upIsGood);
    }

    /**
     * @param  int  $direction  1 up, -1 down, 0 unchanged
     */
    private function describe(Stat $stat, string $text, int $direction, bool $upIsGood): void
    {
        $stat
            ->description($text)
            ->descriptionIcon(match ($direction) {
                1 => Heroicon::ArrowTrendingUp,
                -1 => Heroicon::ArrowTrendingDown,
                default => Heroicon::Minus,
            })
            ->descriptionColor(match (true) {
                $direction === 0 => 'gray',
                ($direction === 1) === $upIsGood => 'success',
                default => 'danger',
            });
    }

    /**
     * What the comparison window is called beside a change: "the day
     * before" for a one-day window, otherwise "the previous N days".
     */
    private function comparisonName(Overview $overview): string
    {
        $days = $overview->window->days();

        return $days === 1 ? 'the day before' : "the previous {$days} days";
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }
}
