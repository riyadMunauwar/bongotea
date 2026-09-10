<?php

namespace App\Support\Reporting;

use App\Enums\OrderStatus;

/**
 * What the Order status breakdown doughnut draws: how many of the Orders in
 * the window are New, Confirmed and Cancelled, so a Merchant can see how
 * much of the window is still undecided. Every Order in the window is in
 * exactly one count, so the three sum to Orders; a Cancelled Order that
 * was Paid is Cancelled here, because the status is the operator's axis
 * and the money is the Payment Method mix's. Plain data — the widget draws
 * it and never counts.
 */
final readonly class StatusBreakdown
{
    public function __construct(
        public ReportingWindow $window,
        /** Awaiting the confirmation call. */
        public int $new,
        public int $confirmed,
        public int $cancelled,
    ) {}

    public function count(OrderStatus $status): int
    {
        return match ($status) {
            OrderStatus::New => $this->new,
            OrderStatus::Confirmed => $this->confirmed,
            OrderStatus::Cancelled => $this->cancelled,
        };
    }

    /**
     * Every Order captured in the window, whatever became of it: the sum of
     * the three counts.
     */
    public function orders(): int
    {
        return $this->new + $this->confirmed + $this->cancelled;
    }
}
