<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\VisitsOverTime;
use App\Support\Reporting\VisitsOverTimePoint;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Visits over time, half width beside Sales over time: the store's Visits one
 * Merchant day at a time across the Reporting Window, so traffic and money
 * are read side by side.
 *
 * Always a day per point, because the aggregate behind it counts a day. A
 * one-day window is therefore a single figure — the one the Visits tile
 * already gives — and the chart says so rather than drawing a lone bar; and
 * where Visits are not counted it says that, rather than drawing a flat line
 * a Merchant would read as no traffic.
 *
 * The points come whole from `ReportingQuery::visitsOverTime()`; nothing here
 * counts. Lazy, like every report widget, so the shell renders before the
 * query.
 */
class VisitsOverTimeChart extends ChartWidget implements GatedOnDashboard
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 25;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`.
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    /**
     * The window's Visits, once per render, though both the description and
     * the data read them. Private, so Livewire neither carries it between
     * requests nor tries to serialise it.
     */
    private ?VisitsOverTime $visits = null;

    protected ?string $heading = 'Visits over time';

    protected ?string $maxHeight = '18rem';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * Why there is no line, where there is none.
     */
    public function getDescription(): ?string
    {
        $visits = $this->visitsOverTime();

        return match (true) {
            ! $visits->isCounted() => 'Not counted. Switch Visit counting on in Settings → Tracking.',
            $visits->window->spansOneDay() => 'Visits are counted by the day, so a single day is one figure: see the Visits tile above.',
            default => null,
        };
    }

    /**
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    protected function getData(): array
    {
        $visits = $this->visitsOverTime();

        if (! $visits->isCounted() || $visits->window->spansOneDay()) {
            return ['labels' => [], 'datasets' => []];
        }

        return [
            'labels' => array_map(fn (VisitsOverTimePoint $point): string => $point->day->format('j M'), $visits->points),
            'datasets' => [
                [
                    'label' => 'Visits',
                    'data' => array_map(fn (VisitsOverTimePoint $point): int => $point->visits, $visits->points),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    private function visitsOverTime(): VisitsOverTime
    {
        return $this->visits ??= app(ReportingQuery::class)->visitsOverTime(ReportingWindow::fromFilters($this->pageFilters));
    }
}
