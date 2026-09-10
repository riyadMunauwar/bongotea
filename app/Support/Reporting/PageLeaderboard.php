<?php

namespace App\Support\Reporting;

/**
 * What the Page leaderboard draws: one row per Page with Orders in the
 * window, Sales first, and a row per recorded slug for Orders whose Page
 * is gone for good. A Page that took nothing in the window is not here —
 * the board compares Pages that earned, and a Merchant reads absence as
 * "nothing sold". The widget formats and re-sorts; it never counts.
 */
final readonly class PageLeaderboard
{
    public function __construct(
        public ReportingWindow $window,
        /**
         * Highest Sales first, then most Orders, then by title, so two
         * Pages that earned the same read in a stable order.
         *
         * @var list<PageLeaderboardRow>
         */
        public array $rows,
    ) {}
}
