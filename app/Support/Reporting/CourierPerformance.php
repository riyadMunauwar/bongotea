<?php

namespace App\Support\Reporting;

/**
 * What the Courier Report draws: how each Courier actually performed over a
 * Reporting Window, one row per Courier and no row that is all of them.
 *
 * The absence of a total is the design rather than an omission (ADR-0018). A
 * canonical state a given Courier never emits is expected — Steadfast never
 * reports In Transit, RedEx never reports Unknown — so a blended
 * cross-courier funnel is the one view in which that asymmetry would read as
 * fact rather than as silence. Every figure here is broken down, and a screen
 * drawing these rows must not add them up.
 *
 * One row for every Courier the registry names, including one nobody has set
 * up and one that carried nothing in the window: a Courier missing from the
 * table would read as a Courier with nothing to answer for, where the truth
 * is that it was handed nothing.
 */
final readonly class CourierPerformance
{
    public function __construct(
        public ReportingWindow $window,
        /**
         * One per Courier, in the order the registry names them — never
         * ranked, because the figure a Merchant is ranking by is their own
         * choice and two of them disagree.
         *
         * @var list<CourierPerformanceRow>
         */
        public array $rows,
    ) {}
}
