<?php

namespace App\Support\Reporting;

use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * One line of the Needs Attention list: the thing to act on, why it is there,
 * and since when. An Order in two conditions at once is two rows, because each
 * is a different thing to do.
 *
 * The subject is an Order for every condition but one. A shelf running thin is
 * a fact about a Product and about no Order in particular — the units on it
 * are going out across a week of them — so a to-do list that could only name
 * an Order could not carry it at all. Nothing else here changes: the list is
 * still what somebody must act on as things stand, whatever the Reporting
 * Window says.
 */
final readonly class NeedsAttentionRow
{
    public function __construct(
        public Order|Product $subject,
        public AttentionReason $reason,

        /**
         * When the condition began: the capture, the Payment's start, the last
         * Paid Verification, or the Courier's last word.
         *
         * Null where the condition has no beginning anybody recorded. A shelf
         * crossed the line on no day the system knows — cover is read fresh
         * from a rate that moves under it — and a blank is the honest account
         * of that, rather than a date arrived at by working backwards through
         * a velocity that was never stored.
         */
        public ?CarbonImmutable $since = null,

        /**
         * What the condition amounts to, where the condition is a figure: the
         * days of cover left on a shelf running thin.
         *
         * It is on the row rather than folded into the reason's label because
         * the reason is one sentence for every row under it, and five Products
         * all "running out of stock" is not a list anybody can work in order.
         * Null for every other condition, which has no figure to give.
         */
        public ?float $daysOfCover = null,
    ) {}

    /**
     * What the row is about, as the screen names it: an Order by its number,
     * a Product by its name.
     */
    public function name(): string
    {
        return $this->subject instanceof Order
            ? (string) $this->subject->order_number
            : (string) $this->subject->name;
    }

    /**
     * Who is waiting, where somebody is. A shelf running thin keeps no
     * Customer waiting, so the column is blank rather than filled with
     * whoever happens to have ordered it last.
     */
    public function customerName(): ?string
    {
        return $this->subject instanceof Order ? $this->subject->customer_name : null;
    }
}
