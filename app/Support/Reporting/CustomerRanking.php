<?php

namespace App\Support\Reporting;

/**
 * Every Customer who bought in a window, most Kilograms first: a wholesale
 * Customer's size is their weight, and Tier prices make taka a poor measure
 * of it. Buying is an Order counted in Sales, so a Customer whose only Orders
 * in the window were Cancelled is not here.
 */
final readonly class CustomerRanking
{
    public function __construct(
        public ReportingWindow $window,
        /** @var list<CustomerRankingRow> */
        public array $rows,
    ) {}
}
