<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;

/**
 * One slice of a Reporting Window: an hour or a day, as two UTC instants,
 * the end exclusive. A series is one figure per bucket, and a bucket exists
 * whether or not anything was captured inside it.
 */
final readonly class ReportingBucket
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * The start as the Merchant reads it, in the timezone the window was
     * cut in: the hour or the midnight a label names.
     */
    public function startIn(string $timezone): CarbonImmutable
    {
        return $this->start->setTimezone($timezone);
    }

    /**
     * Whether the instant falls inside this bucket.
     */
    public function contains(CarbonImmutable $instant): bool
    {
        return $instant->greaterThanOrEqualTo($this->start) && $instant->lessThan($this->end);
    }
}
