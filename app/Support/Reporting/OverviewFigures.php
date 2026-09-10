<?php

namespace App\Support\Reporting;

/**
 * The counts one span of Orders comes to, by the glossary's rules: a whole
 * Reporting Window, its comparison window, or one bucket of it. Every
 * figure that can be read straight off the rows is a property; the two
 * that are ratios of them are methods, so a rate is never stored beside
 * the counts it disagrees with.
 *
 * Money is integer minor units, like everywhere else; formatting is the
 * caller's, through `Money`.
 */
final readonly class OverviewFigures
{
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
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    /**
     * The Orders that count towards Sales.
     */
    public function notCancelled(): int
    {
        return $this->orders - $this->cancelled;
    }

    /**
     * Sales over the Orders that count towards it, in minor units, rounded.
     * Zero, not undefined, when there are none.
     */
    public function averageOrderValue(): int
    {
        $counted = $this->notCancelled();

        return $counted === 0 ? 0 : (int) round($this->sales / $counted);
    }

    /**
     * Cancelled as a share of Orders, from 0 to 1. Zero when there are none.
     */
    public function cancellationRate(): float
    {
        return $this->orders === 0 ? 0.0 : $this->cancelled / $this->orders;
    }

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
