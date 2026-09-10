<?php

namespace App\Filament\Widgets;

use App\Support\Money;
use App\Support\Reporting\BucketSize;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SalesOverTime;
use App\Support\Reporting\SalesOverTimePoint;
use App\Support\Settings\GeneralSettings;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Js;

/**
 * Sales over time, full width under the stat tiles: a Sales line and an
 * Orders line across the Reporting Window, so a Merchant can see whether
 * Sales moved because of volume or because of value. Each has its own axis,
 * money on the left in the installation's currency and a count on the
 * right, since the two are never on one scale.
 *
 * The points come whole from `ReportingQuery::salesOverTime()`: one per
 * hour of a one-day window, one per day otherwise, an empty bucket already
 * a zero, so a quiet day is a dip and not a gap. Nothing here counts; this
 * widget labels the buckets and hands the figures to Chart.js.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class SalesOverTimeChart extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * The neutral the Orders line is drawn in, so blue stays the only
     * chromatic thing in the panel: Sales takes the theme's primary as every
     * chart does, and Orders is told apart by this and a dash. The same
     * shade reads on both the light and the dark surface.
     */
    private const string ORDERS_LINE_COLOR = '#737373';

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Sales over time';

    protected ?string $maxHeight = '18rem';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    protected function getData(): array
    {
        $salesOverTime = app(ReportingQuery::class)->salesOverTime(ReportingWindow::fromFilters($this->pageFilters));

        return [
            'labels' => $this->labels($salesOverTime),
            'datasets' => [
                [
                    'label' => 'Sales',
                    'data' => $salesOverTime->series(fn (SalesOverTimePoint $point): float => Money::toMajor($point->sales)),
                    'yAxisID' => 'y',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Orders',
                    'data' => $salesOverTime->series(fn (SalesOverTimePoint $point): int => $point->orders),
                    'yAxisID' => 'y1',
                    'tension' => 0.3,
                    'borderColor' => self::ORDERS_LINE_COLOR,
                    'borderDash' => [4, 4],
                ],
            ],
        ];
    }

    /**
     * One label per point, as the Merchant reads the clock: the hour for a
     * one-day window, the day and month otherwise.
     *
     * @return list<string>
     */
    private function labels(SalesOverTime $salesOverTime): array
    {
        $format = $salesOverTime->size() === BucketSize::Hour ? 'H:i' : 'j M';
        $timezone = $salesOverTime->window->timezone;

        return $salesOverTime->series(fn (SalesOverTimePoint $point): string => $point->bucket->startIn($timezone)->format($format));
    }

    /**
     * Two axes: money on the left, ticked with the currency symbol and its
     * decimals so the axis agrees with the tiles; Orders on the right, whole
     * numbers only, its grid left off so the chart has one set of lines.
     * Hovering anywhere on a bucket shows both figures for it.
     */
    protected function getOptions(): RawJs
    {
        $general = app(GeneralSettings::class);
        $symbol = Js::from($general->currencySymbol)->toHtml();
        $decimals = $general->currencyDecimals;

        return RawJs::make(<<<JS
            {
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: true, position: 'bottom' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: {
                            callback: (value) => {$symbol} + value.toLocaleString(undefined, { minimumFractionDigits: {$decimals}, maximumFractionDigits: {$decimals} }),
                        },
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        border: { display: false },
                        grid: { drawOnChartArea: false },
                        ticks: { precision: 0 },
                    },
                },
            }
        JS);
    }
}
