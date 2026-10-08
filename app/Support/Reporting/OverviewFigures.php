<?php

namespace App\Support\Reporting;

/**
 * The counts one span of Orders comes to, by the glossary's rules: a whole
 * Reporting Window, its comparison window, or one bucket of it. Every
 * figure that can be read straight off the rows is a property; the three
 * that are ratios of them are methods, shared with the leaderboard's rows
 * through `HasSalesRatios`, so a rate is never stored beside the counts it
 * disagrees with.
 *
 * Money is integer minor units, like everywhere else; formatting is the
 * caller's, through `Money`.
 */
final readonly class OverviewFigures
{
    use HasSalesRatios;

    public function __construct(
        /** Every Order captured in the span, whatever became of it. */
        public int $orders,
        /** The totals, Delivery Charge included, of the Orders not Cancelled. */
        public int $sales,
        public int $cancelled,
        /** An online method and no Paid Payment, as `Order::unpaid()` reads it. */
        public int $unpaid,
        /** Still New: awaiting the confirmation call. */
        public int $new,
        /**
         * Storefront loads the span covers; null where the Merchant is not
         * counting Visits.
         *
         * Only a whole window ever carries one. The Visit aggregate is a
         * count per day, and a bucket may be an hour, so a bucket's Visits
         * are not a figure that exists — which is why this is filled by
         * `withVisits()` after the buckets have been summed rather than
         * counted alongside them, and why nothing draws a Visits sparkline.
         */
        public ?int $visits = null,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    /**
     * The same figures with the span's Visits attached: what a window has and
     * a bucket of it does not.
     */
    public function withVisits(?int $visits): self
    {
        return new self($this->orders, $this->sales, $this->cancelled, $this->unpaid, $this->new, $visits);
    }

    /**
     * Two spans' counts added. Visits are not among them: the spans this is
     * used to add are buckets, and a bucket carries none.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->orders + $other->orders,
            $this->sales + $other->sales,
            $this->cancelled + $other->cancelled,
            $this->unpaid + $other->unpaid,
            $this->new + $other->new,
        );
    }
}
