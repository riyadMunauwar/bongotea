<?php

namespace App\Support\Reporting;

/**
 * Why an Order is on the Needs Attention list: the seven things an operator
 * must act on whatever the Reporting Window says. In the order the list
 * draws them — the call nobody made first, then the money, then the parcels
 * that went wrong.
 *
 * The three parcel conditions are the only ones read off a Shipment rather
 * than off an Order, and they are here for the reason the rest are: a parcel
 * that came back, one that half arrived and one nobody has heard about are
 * all facts the system knows and nothing surfaces (ADR-0018). None of them is
 * a status — a return changes no `OrderStatus` and a User decides what
 * becomes of the Order — so a to-do list is the only place any of them can
 * appear.
 *
 * A half-arrived parcel is the one whose answer the ledger itself is waiting
 * on: until a User names the lines that came back, the units on it are
 * neither returned nor lost (ADR-0021), and answering is the only thing that
 * takes it off this list.
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
     * A parcel that half arrived and nobody has said which items came back:
     * the Courier reports a collected amount and never a list, so the units
     * are neither returned nor lost until a User names the lines (ADR-0021).
     */
    case ParcelPartlyDelivered;

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
            self::ParcelPartlyDelivered => 'Parcel half arrived',
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
            self::ParcelPartlyDelivered => 'Say which items came back',
            self::ParcelStuck => 'Chase the Courier',
        };
    }
}
