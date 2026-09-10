<?php

namespace App\Support\Reporting;

/**
 * What the Top Products table draws: one row per Product sold in the
 * window, highest revenue first, so a Merchant knows what to stock. Read
 * from the Order Lines of the Orders that are not Cancelled — a Cancelled
 * Order sold nothing — and merged by Product, so a Product that appears on
 * a hundred Orders is one row. A Product nothing sold in the window is not
 * here. The widget formats and re-sorts; it never counts.
 */
final readonly class ProductRanking
{
    public function __construct(
        public ReportingWindow $window,
        /**
         * Highest revenue first, then most units, then by name, so two
         * Products that earned the same read in a stable order.
         *
         * @var list<ProductRankingRow>
         */
        public array $rows,
    ) {}
}
