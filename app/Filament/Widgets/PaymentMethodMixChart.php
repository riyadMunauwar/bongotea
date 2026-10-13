<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Money;
use App\Support\Reporting\PaymentMixRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Js;

/**
 * The Payment Method mix, half width beside the status breakdown: one
 * group of bars per Payment Method the window's Orders recorded, so a
 * Merchant can see whether a gateway is earning its fee. Each method has
 * an Orders bar, a Sales bar and a Kilograms Sold bar beside it; the
 * Orders bar of an online method is
 * stacked Paid over Unpaid, in the colours the payment badge wears in the
 * queue, while a Cash on Delivery Order is simply due and its bar is one
 * neutral block. Sales is drawn in the theme's primary, as every chart's
 * money is, on its own axis since a count and an amount are never on one
 * scale.
 *
 * The rows come whole from `ReportingQuery::paymentMix()`; nothing here
 * counts. A window with no Orders draws an empty chart rather than an
 * error.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class PaymentMethodMixChart extends ChartWidget implements GatedOnDashboard
{
    use InteractsWithPageFilters;

    /**
     * The three parts of an Orders bar, in the colours the payment badge
     * uses: the panel's success for Paid, danger for Unpaid, and the neutral
     * a Cash on Delivery badge is drawn in. Chart.js takes hex, not a
     * palette name, so the shades are spelled here.
     */
    private const string PAID_COLOR = '#22c55e';

    private const string UNPAID_COLOR = '#ef4444';

    private const string DUE_ON_DELIVERY_COLOR = '#a3a3a3';

    /**
     * The neutral the Kilograms Sold bar is drawn in: darker than the due
     * block so the two greys are not read as one figure, and not chromatic,
     * so Sales stays the only bar in the theme's primary.
     */
    private const string KILOGRAMS_COLOR = '#525252';

    /**
     * The name Chart.js stacks the three Orders datasets under, so they
     * make one bar and the Sales bar stands beside it.
     */
    private const string ORDERS_STACK = 'orders';

    protected static ?int $sort = 40;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`
     * (#161).
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    protected ?string $heading = 'Payment Method mix';

    protected ?string $maxHeight = '18rem';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    protected function getData(): array
    {
        $mix = app(ReportingQuery::class)->paymentMix(ReportingWindow::fromFilters($this->pageFilters));

        return [
            'labels' => $mix->series(fn (PaymentMixRow $row): string => $row->method->getLabel()),
            'datasets' => [
                [
                    'label' => 'Paid',
                    'data' => $mix->series(fn (PaymentMixRow $row): int => $row->paid() ?? 0),
                    'backgroundColor' => self::PAID_COLOR,
                    'stack' => self::ORDERS_STACK,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Unpaid',
                    'data' => $mix->series(fn (PaymentMixRow $row): int => $row->unpaid ?? 0),
                    'backgroundColor' => self::UNPAID_COLOR,
                    'stack' => self::ORDERS_STACK,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Due on delivery',
                    'data' => $mix->series(fn (PaymentMixRow $row): int => $row->isSplit() ? 0 : $row->orders),
                    'backgroundColor' => self::DUE_ON_DELIVERY_COLOR,
                    'stack' => self::ORDERS_STACK,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Sales',
                    'data' => $mix->series(fn (PaymentMixRow $row): float => Money::toMajor($row->sales)),
                    'yAxisID' => 'y1',
                ],
                [
                    'label' => 'Kilograms Sold',
                    'data' => $mix->series(fn (PaymentMixRow $row): int|float => $row->kilograms),
                    'backgroundColor' => self::KILOGRAMS_COLOR,
                    'yAxisID' => 'y2',
                ],
            ],
        ];
    }

    /**
     * Three axes: Orders on the left, whole numbers only; Sales on the right,
     * ticked with the currency symbol and its decimals so the axis agrees
     * with the tiles, and Kilograms Sold beside it in kilograms, their grids
     * left off so the chart has one set of lines. Hovering a method shows
     * every figure for it.
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
                    x: { stacked: true },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        stacked: true,
                        ticks: { precision: 0 },
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        border: { display: false },
                        grid: { drawOnChartArea: false },
                        ticks: {
                            callback: (value) => {$symbol} + value.toLocaleString(undefined, { minimumFractionDigits: {$decimals}, maximumFractionDigits: {$decimals} }),
                        },
                    },
                    y2: {
                        beginAtZero: true,
                        position: 'right',
                        border: { display: false },
                        grid: { drawOnChartArea: false },
                        ticks: {
                            precision: 0,
                            callback: (value) => value.toLocaleString() + ' kg',
                        },
                    },
                },
            }
        JS);
    }
}
