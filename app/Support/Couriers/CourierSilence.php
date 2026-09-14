<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use Illuminate\Http\Client\Response;

/**
 * An answer from a Courier that settles nothing — the one reading of it,
 * shared by every call this system makes.
 *
 * It is one rule and not three: a 5xx is a Courier having a bad morning, a
 * 429 or a 408 is a Courier declining to deal with us this minute, and a
 * rejected credential is a Merchant's Settings rather than anything about the
 * parcel in hand. None of them is the Courier saying a word about the parcel,
 * so none of them may be written down as if it were — not onto a timeline
 * (ADR-0019), and not as a refusal that takes a real parcel permanently out of
 * the queue (ADR-0020).
 *
 * Both halves of the system read it here. A poll wraps it in
 * `ShipmentProgress::unreachable()` and writes nothing at all; a booking
 * raises it as one of the two `BookingNotMade`s that mean "not this parcel's
 * fault". They were separate readings once, and the booking half simply did
 * not have one: every answer that was not a Consignment was read as the
 * Courier refusing the parcel, so a gateway error mid-sweep wrote off a
 * hundred parcels a Courier had never been shown.
 *
 * The credential case is kept apart from the rest because retrying is the
 * right advice for one and useless for the other, and because the sweep
 * retries only the silence.
 */
final class CourierSilence
{
    private function __construct(
        public readonly string $reason,
        public readonly bool $isCredentials,
    ) {}

    /**
     * The silence in this answer, or null where the Courier was answering
     * about the parcel and its driver must read what it said.
     */
    public static function in(Response $response, Courier $courier): ?self
    {
        return self::inStatus($response->status(), $courier);
    }

    /**
     * The same reading, taken from a status code alone.
     *
     * Pathao is why this is reachable without a `Response`: its driver reads
     * every answer through `PathaoAnswer`, which keeps the body and the status
     * and lets the response itself go. A second reading of silence written
     * against the envelope is exactly what this class exists to prevent.
     */
    public static function inStatus(int $status, Courier $courier): ?self
    {
        $label = $courier->getLabel();

        return match (true) {
            $status >= 500 => new self(
                "{$label} answered {$status} and said nothing about the parcel.",
                isCredentials: false,
            ),

            /**
             * Declining to deal with us this minute, which is the answer a
             * sweep is most likely to provoke: a run of two hundred parcels
             * one at a time is exactly what a rate limit exists to stop, and
             * every parcel after the limit would otherwise be written off as
             * refused by a Courier that never saw it.
             */
            $status === 429, $status === 408 => new self(
                "{$label} would not take the call just now (HTTP {$status}) and said nothing about the parcel.",
                isCredentials: false,
            ),

            $status === 401, $status === 403 => new self(
                "{$label} would not accept this installation's credentials (HTTP {$status}).",
                isCredentials: true,
            ),

            default => null,
        };
    }

    /**
     * What this silence amounts to when a parcel was being booked: a Courier
     * to try again in a moment, or one this installation cannot speak to
     * until a Merchant fixes its Settings.
     *
     * Never `CourierRefusedBooking`, which is the Courier's own answer about
     * the parcel and is final — the distinction the whole class exists for.
     *
     * @param  array<string, mixed>  $context
     */
    public function bookingNotMade(array $context = []): BookingNotMade
    {
        return $this->isCredentials
            ? new CourierNotUsable($this->reason, $context)
            : new CourierUnreachable($this->reason, $context);
    }
}
