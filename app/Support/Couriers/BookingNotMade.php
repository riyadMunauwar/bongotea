<?php

namespace App\Support\Couriers;

use RuntimeException;

/**
 * A parcel was not booked, and nothing was recorded as sent.
 *
 * Never a Shipment in a failed state: a booking is made inside the request
 * while a User waits (ADR-0020), so an attempt that did not happen leaves the
 * Order exactly as it was and the reason is worded back to the operator
 * instead. Where a Courier said something, its answer rides along whole, so
 * that the wording an operator reads is the Courier's own rather than ours.
 *
 * Four reasons and four classes, because they are not the same fact to
 * anything that retries or to anybody reading the sentence: a Courier's own
 * refusal is an answer, a Courier that could not be reached is silence, a
 * Courier this installation cannot speak to was never asked at all, and a
 * parcel with no delivery area on it waits on a human choosing one.
 */
abstract class BookingNotMade extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response  The Courier's answer, or the failure, as it arrived.
     */
    public function __construct(string $message, public readonly array $response = [])
    {
        parent::__construct($message);
    }
}
