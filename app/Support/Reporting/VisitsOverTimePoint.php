<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;

/**
 * One Merchant day of the Visits line: the day as it is read, and the loads
 * the store counted on it.
 */
final readonly class VisitsOverTimePoint
{
    public function __construct(
        /** Midnight of the day, in the Merchant's timezone. */
        public CarbonImmutable $day,
        public int $visits,
    ) {}
}
