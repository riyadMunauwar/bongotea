<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Support\Money;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\StockHoldings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The two figures at the top of the Stock report: what the shelf is worth
 * altogether, and how many of the Products beneath it that figure is drawn
 * over.
 *
 * The second tile is there because of the first. Adoption of the ledger is
 * deliberately gradual — a Merchant counts the five Products that matter this
 * afternoon and the rest sit unknown until somebody cares — so a money figure
 * drawn alone would be read as the whole shelf when it is the counted part of
 * it. Saying how many Products are behind it is the only honest way to draw
 * one number over a ledger somebody is halfway through adopting. Its
 * denominator is the report's own rows and not the catalogue: a Product
 * withdrawn from sale but still holding units is on this report, and the money
 * tile counts it.
 *
 * Neither tile carries a change or a sparkline, and cannot: both are as
 * things stand this moment, and there is no comparison window on a screen
 * with no window at all.
 *
 * Where nothing bought has ever been recorded the money tile says so in words
 * rather than drawing a nought — an installation that has recorded no
 * deliveries has not established that it holds nothing, and a zero in a money
 * column would say it had. The same reading the Visits tile takes of counting
 * switched off.
 *
 * The counting is `ReportingQuery::stockHoldings()`'s and nothing here counts.
 */
class StockValueStat extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'What is held';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $holdings = app(ReportingQuery::class)->stockHoldings();

        return [
            $this->valueHeld($holdings),
            $this->counted($holdings),
        ];
    }

    /**
     * The capital sitting on the shelf, at what it cost.
     */
    private function valueHeld(StockHoldings $holdings): Stat
    {
        if ($holdings->valueHeld === null) {
            return Stat::make('Value at cost held', 'Not known')
                ->description('Record a delivery and what is held gets a price')
                ->descriptionColor('gray');
        }

        return Stat::make('Value at cost held', Money::format($holdings->valueHeld))
            ->description('At what the units on the shelf cost')
            ->descriptionColor('gray');
    }

    /**
     * How many of the Products on this report the figure beside it covers.
     */
    private function counted(StockHoldings $holdings): Stat
    {
        $counted = $holdings->counted();
        $products = count($holdings->rows);

        return Stat::make('Products in the ledger', number_format($counted).' of '.number_format($products))
            ->description($counted === $products
                ? 'Every Product has been counted or delivered'
                : 'The rest read as unknown until somebody counts them')
            ->descriptionColor('gray');
    }
}
