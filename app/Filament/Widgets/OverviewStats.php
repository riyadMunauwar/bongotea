<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ScopedToPage;
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
 * by side, never collapsed into one; Average Order Value and the
 * Cancellation Rate; Unpaid and New awaiting call, the two queues; and the
 * Visits tile, reserved so the row does not shift when counting arrives —
 * a Visit is named in the glossary and not yet counted.
 *
 * Each tile reads its change against the comparison window, coloured by
 * whether up is good — it is not for the Cancellation Rate or Unpaid — and
 * carries the window's buckets as a sparkline. The figures come whole from
 * `ReportingQuery`; nothing here counts, and money is drawn through
 * `Money` so the currency Setting is honoured.
 *
 * One class in both places it is drawn: the whole installation on the
 * Dashboard, and one Page on that Page's edit screen, which is the record
 * `ScopedToPage` hands to the module — nothing else here changes.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class OverviewStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use ScopedToPage;

    protected static ?int $sort = 10;

    protected ?string $heading = 'Overview';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $overview = app(ReportingQuery::class)->overview(ReportingWindow::fromFilters($this->pageFilters), $this->record);

        $current = $overview->figures;
        $previous = $overview->comparison;

        return [
            $this->tile('Orders', number_format($current->orders), $overview, fn (OverviewFigures $figures): int => $figures->orders)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->orders, $previous->orders, upIsGood: true)),

            $this->tile('Sales', Money::format($current->sales), $overview, fn (OverviewFigures $figures): int => $figures->sales)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->sales, $previous->sales, upIsGood: true)),

            $this->tile('Average Order Value', Money::format($current->averageOrderValue()), $overview, fn (OverviewFigures $figures): int => $figures->averageOrderValue())
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->averageOrderValue(), $previous->averageOrderValue(), upIsGood: true)),

            $this->tile('Cancellation Rate', $this->percent($current->cancellationRate()), $overview, fn (OverviewFigures $figures): float => round($figures->cancellationRate() * 100, 1))
                ->tap(fn (Stat $stat) => $this->describeRateChange($stat, $overview, $current->cancellationRate(), $previous->cancellationRate())),

            $this->tile('Unpaid', number_format($current->unpaid), $overview, fn (OverviewFigures $figures): int => $figures->unpaid)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->unpaid, $previous->unpaid, upIsGood: false)),

            $this->tile('New awaiting call', number_format($current->new), $overview, fn (OverviewFigures $figures): int => $figures->new)
                ->tap(fn (Stat $stat) => $this->describeChange($stat, $overview, $current->new, $previous->new, upIsGood: true)),

            Stat::make('Visits', 'Not yet counted')
                ->description('Visit counting comes in a later release')
                ->descriptionColor('gray'),
        ];
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
    private function describeChange(Stat $stat, Overview $overview, int $current, int $previous, bool $upIsGood): void
    {
        $against = $this->comparisonName($overview);

        if ($current === $previous) {
            $this->describe($stat, "No change on {$against}", 0, $upIsGood);

            return;
        }

        if ($previous === 0) {
            $this->describe($stat, 'Up from none', 1, $upIsGood);

            return;
        }

        $change = ($current - $previous) / $previous * 100;
        $direction = $change > 0 ? 'up' : 'down';

        $this->describe($stat, number_format(abs($change)).'% '.$direction." on {$against}", $change <=> 0, $upIsGood);
    }

    /**
     * The change in a rate, in percentage points rather than a percentage
     * of a percentage: "3.2 pts up on the previous 7 days". Up is never
     * good for a rate drawn here.
     */
    private function describeRateChange(Stat $stat, Overview $overview, float $current, float $previous): void
    {
        $against = $this->comparisonName($overview);
        $points = round(($current - $previous) * 100, 1);

        if ($points === 0.0) {
            $this->describe($stat, "No change on {$against}", 0, upIsGood: false);

            return;
        }

        $direction = $points > 0 ? 'up' : 'down';

        $this->describe($stat, number_format(abs($points), 1).' pts '.$direction." on {$against}", $points <=> 0, upIsGood: false);
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
