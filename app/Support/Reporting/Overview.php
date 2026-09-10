<?php

namespace App\Support\Reporting;

/**
 * What the stat tiles draw: the window's figures, the comparison window's
 * to measure a change against, and the window's figures bucket by bucket
 * for the sparklines. Plain data — a widget formats it and never counts.
 */
final readonly class Overview
{
    public function __construct(
        public ReportingWindow $window,
        public OverviewFigures $figures,
        /** The same figures over `$window->comparison()`. */
        public OverviewFigures $comparison,
        /**
         * One entry per `$window->buckets()`, in order, an empty bucket
         * reading as zeros. Their sum is `$figures`.
         *
         * @var list<OverviewFigures>
         */
        public array $buckets,
    ) {}

    /**
     * One figure per bucket, for a sparkline.
     *
     * @param  callable(OverviewFigures): (int|float)  $figure
     * @return list<int|float>
     */
    public function series(callable $figure): array
    {
        return array_map($figure, $this->buckets);
    }
}
