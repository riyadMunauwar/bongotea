<?php

namespace App\Support\Reporting;

use App\Support\Kilograms;

/**
 * The counts one span of Orders comes to, by the glossary's rules: a whole
 * Reporting Window, its comparison window, or one bucket of it. Every
 * figure that can be read straight off the rows is a property; the three
 * that are ratios of them are methods, written once in
 * `HasSalesRatios`, so a rate is never stored beside the counts it
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
        /** Kilograms Sold: the Kilograms on those very Orders, the weight side of `$sales`. */
        public int|float $kilograms,
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
        /**
         * Customers who bought in the span for the first time, and Customers
         * who bought in it having bought before it began. Buying is an Order
         * counted in Sales.
         *
         * A Customer who buys on two days of a window is one Customer to the
         * window and one to each of the two buckets, so these never add up
         * from the buckets: a window's are set whole by `withCustomers()`,
         * and `plus()` carries none.
         */
        public int $newCustomers = 0,
        public int $returningCustomers = 0,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, 0);
    }

    /**
     * The same figures with the span's Visits attached: what a window has and
     * a bucket of it does not.
     */
    public function withVisits(?int $visits): self
    {
        return new self($this->orders, $this->sales, $this->kilograms, $this->cancelled, $this->unpaid, $this->new, $visits, $this->newCustomers, $this->returningCustomers);
    }

    /**
     * The same figures with the span's New and Returning Customers set, each
     * counted over the span whole.
     */
    public function withCustomers(int $newCustomers, int $returningCustomers): self
    {
        return new self($this->orders, $this->sales, $this->kilograms, $this->cancelled, $this->unpaid, $this->new, $this->visits, $newCustomers, $returningCustomers);
    }

    /**
     * Two spans' counts added. Visits and Customers are not among them: the
     * spans this is used to add are buckets, a bucket carries no Visits, and
     * one Customer in two buckets is still one Customer.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->orders + $other->orders,
            $this->sales + $other->sales,
            Kilograms::sum([$this->kilograms, $other->kilograms]),
            $this->cancelled + $other->cancelled,
            $this->unpaid + $other->unpaid,
            $this->new + $other->new,
        );
    }
}
