<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The Order status breakdown, half width: a doughnut
 * of New, Confirmed and Cancelled over the Reporting Window, so a Merchant
 * can see how much of the window is still undecided. Each slice is drawn
 * in the colour the status badge wears in the queue, so the chart and the
 * Orders list say the same thing in the same colour.
 *
 * The counts come whole from `ReportingQuery::statusBreakdown()`; nothing
 * here counts. A window with no Orders draws an empty ring rather than an
 * error.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class OrderStatusBreakdownChart extends ChartWidget implements GatedOnDashboard
{
    use InteractsWithPageFilters;

    /**
     * The slice colours, one per status in the order the ring is drawn:
     * the panel's warning, success and danger, which is what the status
     * badges are drawn in. Chart.js takes hex, not a palette name, so the
     * shades are spelled here.
     *
     * @var array<value-of<OrderStatus>, string>
     */
    private const array SLICE_COLORS = [
        'new' => '#f59e0b',
        'confirmed' => '#22c55e',
        'cancelled' => '#ef4444',
    ];

    protected static ?int $sort = 30;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`
     * (#161).
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    protected ?string $heading = 'Order status breakdown';

    protected ?string $maxHeight = '18rem';

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    protected function getData(): array
    {
        $breakdown = app(ReportingQuery::class)->statusBreakdown(ReportingWindow::fromFilters($this->pageFilters));
        $statuses = OrderStatus::cases();

        return [
            'labels' => array_map(fn (OrderStatus $status): string => $status->getLabel(), $statuses),
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => array_map(fn (OrderStatus $status): int => $breakdown->count($status), $statuses),
                    'backgroundColor' => array_map(fn (OrderStatus $status): string => self::SLICE_COLORS[$status->value], $statuses),
                ],
            ],
        ];
    }

    /**
     * The legend under the ring, as every chart on the Dashboard has it;
     * the ring itself needs no axes.
     */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: { legend: { display: true, position: 'bottom' } },
            }
        JS);
    }
}
