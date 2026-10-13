<?php

namespace App\Support\Reporting;

/**
 * One bucket of a Sales over time series: the slice of the window and the
 * figures a Merchant reads against it, Orders and Sales, side by side as
 * the glossary insists, and Kilograms Sold beside Sales. A bucket nothing was captured in is a point at
 * zero, so a quiet hour draws as a dip and never as a gap.
 */
final readonly class SalesOverTimePoint
{
    public function __construct(
        public ReportingBucket $bucket,
        /** Every Order captured in the bucket, whatever became of it. */
        public int $orders,
        /** The totals, Delivery Charge included, of the ones not Cancelled, in minor units. */
        public int $sales,
        /** Kilograms Sold: the Kilograms on those same Orders. */
        public int|float $kilograms,
    ) {}
}
