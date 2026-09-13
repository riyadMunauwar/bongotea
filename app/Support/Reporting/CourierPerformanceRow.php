<?php

namespace App\Support\Reporting;

use App\Enums\Courier;

/**
 * One Courier's own figures over a Reporting Window, and never anybody
 * else's.
 *
 * Every row on the Courier Report is one of these, and there is no row that
 * is all of them added up: the couriers do not answer the same questions, so
 * a blended figure would present one Courier's silence as the whole trade's
 * (ADR-0018).
 *
 * That is also why three of the six figures are nullable. A count is zero
 * when a Courier carried nothing, which is true of it; a **rate** with no
 * parcels under it is unknown rather than nought, and a total charge no
 * Courier has named is unknown rather than free — Steadfast takes a parcel
 * without quoting anything and says what it cost later. A blank in a money
 * column reads as a zero, and a zero there tells a Merchant the carriage was
 * free, which is the one thing it never is. The same reading `Balance
 * (courier-reported)` already takes, and the same one `conversionRate()`
 * takes of a Visit nobody counted.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class CourierPerformanceRow
{
    public function __construct(
        public Courier $courier,
        /** Parcels this Courier accepted inside the window. */
        public int $sent,
        /** Of those, the ones that got to a door — all of the parcel or some of it. */
        public int $delivered,
        /** Of those, the ones that came back. */
        public int $returned,
        /**
         * What this Courier has said it charged to carry them, added up over
         * the parcels it has named a charge for — and null where it has named
         * none at all.
         */
        public ?int $charges,
        /**
         * How long this Courier took over the ones that arrived, in days, or
         * null where none of them did.
         */
        public ?float $averageDaysToDelivery,
    ) {}

    /**
     * The parcels that arrived as a share of the parcels sent, from 0 to 1,
     * and null where none were sent.
     *
     * Over everything handed to this Courier in the window rather than over
     * the attempts it has finished with, so the figure is a share of the
     * Merchant's own traffic and a parcel still moving is neither a success
     * nor a failure. Delivery Rate and Return Rate therefore need not sum to
     * one: what is left over is out for delivery, held, or written off.
     */
    public function deliveryRate(): ?float
    {
        return $this->sent === 0 ? null : $this->delivered / $this->sent;
    }

    /**
     * The parcels that came back as a share of the parcels sent, from 0 to 1,
     * and null where none were sent. Read as `deliveryRate()` is.
     */
    public function returnRate(): ?float
    {
        return $this->sent === 0 ? null : $this->returned / $this->sent;
    }
}
