<?php

namespace App\Support\Reporting;

/**
 * What the Sales over time chart draws: one point per bucket of the window,
 * in order, hourly for a one-day window and daily otherwise. The chart
 * labels the axis and plots the lines; it never sums anything, because the
 * points already are the figures.
 */
final readonly class SalesOverTime
{
    public function __construct(
        public ReportingWindow $window,
        /**
         * One entry per `$window->buckets()`, in order, every bucket present.
         *
         * @var list<SalesOverTimePoint>
         */
        public array $points,
    ) {}

    /**
     * Whether the points are hours or days: the window's own choice.
     */
    public function size(): BucketSize
    {
        return $this->window->bucketSize();
    }

    /**
     * One figure per point, for a chart's dataset.
     *
     * @param  callable(SalesOverTimePoint): (int|float|string)  $figure
     * @return list<int|float|string>
     */
    public function series(callable $figure): array
    {
        return array_map($figure, $this->points);
    }
}
