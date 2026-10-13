<?php

namespace App\Support\Reporting;

use App\Enums\PaymentMethod;

/**
 * One bar of the Payment Method mix: a Payment Method and what the Orders
 * that chose it in the window came to, by the glossary's rules, with an
 * online method's Orders split between Paid and Unpaid.
 *
 * The split is `Order::unpaid()`'s reading and not a rule of its own: Unpaid
 * is an online method and no Paid Payment, and Paid is the rest of that
 * method's Orders — so a Cancelled Order that was Paid is Paid here, and an
 * Order whose only Payment is Pending is Unpaid. Cash on Delivery has no
 * split, because the glossary says an Order paid that way is simply due;
 * both halves read as null rather than as a misleading zero, and the chart
 * draws its bar as one block, due on delivery.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class PaymentMixRow
{
    public function __construct(
        public PaymentMethod $method,
        /** Every Order in the window that chose the method, whatever became of it. */
        public int $orders,
        /** The totals, Delivery Charge included, of those Orders not Cancelled. */
        public int $sales,
        /** Kilograms Sold: the Kilograms on those same Orders not Cancelled. */
        public int|float $kilograms,
        /** Of an online method's Orders, those with no Paid Payment; null for Cash on Delivery. */
        public ?int $unpaid,
    ) {}

    /**
     * Of an online method's Orders, those holding a Paid Payment: the rest,
     * once the Unpaid are taken away. Null for Cash on Delivery, like
     * `$unpaid`. A method over the counts rather than a stored figure, so
     * Paid can never disagree with Orders and Unpaid.
     */
    public function paid(): ?int
    {
        return $this->unpaid === null ? null : $this->orders - $this->unpaid;
    }

    /**
     * Whether the row's Orders are split between Paid and Unpaid, which is
     * whether the method is an online one.
     */
    public function isSplit(): bool
    {
        return $this->unpaid !== null;
    }
}
