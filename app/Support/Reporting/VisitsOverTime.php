<?php

namespace App\Support\Reporting;

/**
 * What the Visits line draws: the store's Visits one Merchant day at a time.
 *
 * Always a day per point, never an hour, because the aggregate is a count per
 * day — which is why a one-day window has a single figure rather than a line,
 * and the chart says so instead of drawing one bar. No points at all where the
 * Merchant is not counting Visits.
 */
final readonly class VisitsOverTime
{
    public function __construct(
        public ReportingWindow $window,
        public bool $counted,
        /** @var list<VisitsOverTimePoint> */
        public array $points,
    ) {}

    public function isCounted(): bool
    {
        return $this->counted;
    }
}
