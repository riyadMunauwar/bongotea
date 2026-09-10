<?php

namespace App\Support\Reporting;

/**
 * Why an Order is on the Needs Attention list: the four things an operator
 * must act on whatever the Reporting Window says. In the order the list
 * draws them — the call nobody made first, then the money.
 */
enum AttentionReason
{
    /** A New Order older than a day: no Customer should wait two for the call. */
    case NewOrderUncalled;

    /** A Payment Pending for over an hour: check with the gateway before the Customer gives up. */
    case PaymentPending;

    /** Two Paid Payments on one Order: one is owed back. */
    case PaidTwice;

    /** Paid and Cancelled at once: the refund is not to be forgotten. */
    case PaidAndCancelled;

    /**
     * The condition, as the list names it.
     */
    public function label(): string
    {
        return match ($this) {
            self::NewOrderUncalled => 'New for over a day',
            self::PaymentPending => 'Payment Pending for over an hour',
            self::PaidTwice => 'Paid twice',
            self::PaidAndCancelled => 'Paid and Cancelled',
        };
    }

    /**
     * What the operator does about it, in a few words.
     */
    public function action(): string
    {
        return match ($this) {
            self::NewOrderUncalled => 'Call the Customer',
            self::PaymentPending => 'Check with the gateway',
            self::PaidTwice => 'Refund one Payment',
            self::PaidAndCancelled => 'Refund the Payment',
        };
    }
}
