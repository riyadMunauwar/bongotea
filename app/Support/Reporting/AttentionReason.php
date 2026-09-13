<?php

namespace App\Support\Reporting;

/**
 * Why an Order is on the Needs Attention list: the six things an operator
 * must act on whatever the Reporting Window says. In the order the list
 * draws them — the call nobody made first, then the money, then the parcels
 * that went wrong.
 *
 * The two parcel conditions are the only ones read off a Shipment rather
 * than off an Order, and they are here for the reason the rest are: a parcel
 * that came back and a parcel nobody has heard about are both facts the
 * system knows and nothing surfaces (ADR-0018). Neither is a status — a
 * return changes no `OrderStatus` and a User decides what becomes of the
 * Order — so a to-do list is the only place either of them can appear.
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
     * The last parcel came back and nobody has said what to do about it:
     * ring the Customer, correct the address and send it again, or cancel
     * the Order. A returned parcel is a question and never an answer.
     */
    case ParcelReturned;

    /**
     * A parcel out so long the sweep has flagged it: roughly a week with
     * nothing anybody can account for. Chase the Courier, and write it off
     * if there is nothing left to wait for.
     */
    case ParcelStuck;

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
            self::ParcelReturned => 'Parcel came back',
            self::ParcelStuck => 'Nothing heard for a week',
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
            self::ParcelReturned => 'Send it again or cancel the Order',
            self::ParcelStuck => 'Chase the Courier',
        };
    }
}
