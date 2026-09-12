<?php

namespace App\Support\Couriers;

use App\Enums\Courier;

/**
 * A Courier that insists on naming a delivery area from its own list was
 * given none, so the parcel was not sent.
 *
 * Refused here rather than by the Courier: a booking with a blank or unknown
 * area is a request RedEx answers with a rejection, which reads to an
 * operator as "RedEx refused this parcel" and sends them to ring RedEx about
 * a box on our own screen. Nothing was asked and nothing was recorded.
 *
 * Its own class beside the other three, because it is not the same fact to
 * anything that retries: a Courier's refusal is final, silence is worth
 * trying again, an unusable Courier is a Merchant's job in Settings — and
 * this one waits on a human choosing an area, which is what the batch sweep
 * will have to hold a parcel back for rather than send.
 *
 * The wording names the other way this happens as well as the obvious one. An
 * operator reaches here either by not answering the question or by being given
 * an empty chooser to answer it with — the Courier's list is fetched, and a
 * Courier that could not be asked for it has none — and "you did not choose
 * one" sends the second of them looking for a box they could not have filled.
 */
class DeliveryAreaNotResolved extends BookingNotMade
{
    public function __construct(public readonly Courier $courier)
    {
        parent::__construct(
            "{$courier->getLabel()} will not take a parcel without one of its own delivery areas, and none was chosen. If there were none to choose from, {$courier->getLabel()} could not be asked for its areas — try again in a moment."
        );
    }
}
