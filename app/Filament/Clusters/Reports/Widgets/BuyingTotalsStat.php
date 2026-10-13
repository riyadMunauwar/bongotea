<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Support\Inventory\Grams;
use App\Support\Money;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the Reporting Window cost altogether: money out, units in, and how
 * many deliveries brought them.
 *
 * Read beside `Sales` over the same days, which is the whole reason the
 * Buying report obeys the window at all — money out and money in are only
 * comparable over the same span, and a buying figure drawn over a fixed span
 * beside a Sales figure drawn over a chosen one would invite exactly the
 * subtraction that makes no sense.
 *
 * Deliveries counts Supplies and never lines: one invoice is one delivery
 * however many Products it named.
 *
 * A **Voided** delivery is in none of these, which is `Buying`'s own rule and
 * is not restated here. Nothing on this screen counts; the figures come whole
 * from `ReportingQuery::buying()` and money is drawn through `Money` so the
 * currency Setting is honoured.
 *
 * Zero rather than a sentence where a window bought nothing, unlike the stock
 * tiles: a window with no deliveries in it really did cost nothing, where an
 * uncounted shelf is a fact nobody has established.
 */
class BuyingTotalsStat extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 0;

    protected ?string $heading = 'Bought in this window';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $buying = app(ReportingQuery::class)->buying(ReportingWindow::fromFilters($this->pageFilters));

        return [
            Stat::make('Spent', Money::format($buying->cost))
                ->description('At what each line was bought for')
                ->descriptionColor('gray'),

            Stat::make('Units bought', Grams::toKilograms($buying->units))
                ->description('Across every Product delivered')
                ->descriptionColor('gray'),

            Stat::make('Deliveries', number_format($buying->deliveries))
                ->description('Voided ones are not among them')
                ->descriptionColor('gray'),
        ];
    }
}
