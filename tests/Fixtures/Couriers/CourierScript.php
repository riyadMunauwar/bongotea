<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;

/**
 * One Courier's documented request and response shapes, scripted for the
 * faked HTTP client, so that the courier tests run once per Courier as a
 * dataset and a later Courier adds a row rather than a file.
 *
 * Every method here speaks in the Courier's own terms: what it answers when a
 * parcel is booked, what it says when it refuses, and which of its own words
 * it reports a state with. What the system makes of those is what the tests
 * assert — mirroring `Tests\Fixtures\Payments\GatewayScript` and
 * `Tests\Fixtures\Tracking\ProviderScript` exactly.
 *
 * The couriers' asymmetries live here as declarations rather than as
 * branching inside a test: a test asks the script what this Courier supports,
 * never which Courier it is, exactly as the application asks the driver.
 */
abstract class CourierScript
{
    abstract public function courier(): Courier;

    /** Switch the Courier on for the installation with its credentials filled, the way a Merchant would. */
    abstract public function makeUsable(): void;

    /** The Courier's own identifier for a parcel it accepted, as it spells one. */
    abstract public function consignmentReference(): string;

    /** The code a Customer follows the parcel by, as this Courier spells one. */
    abstract public function trackingCode(): string;

    /** Fake the Courier accepting a parcel and answering with a Consignment. */
    abstract public function fakeBooked(): void;

    /**
     * Fake the Courier accepting a parcel and reporting it in one of its own
     * status words, so a test can prove that word is translated.
     */
    abstract public function fakeBookedReporting(string $rawStatus): void;

    /** Fake the Courier answering and refusing the parcel. */
    abstract public function fakeBookingRefused(): void;

    /** What the Courier says when it refuses, so a test can prove the operator is told it verbatim. */
    abstract public function refusalWording(): string;

    /** Fake the Courier being unreachable for every call. */
    abstract public function fakeOutage(): void;

    /** Assert a booking went out for this Shipment carrying every field the Courier calls mandatory, filled. */
    abstract public function assertBookedFor(Shipment $shipment): void;

    /** Assert the Courier was asked to take a parcel exactly this many times since the last fake was laid. */
    abstract public function assertBookingsSent(int $count): void;

    /**
     * This Courier's own words for a canonical state — every one of them, so
     * each Courier proves its own vocabulary rather than the abstract proving
     * one on everybody's behalf.
     *
     * Empty where this Courier never reports that state, which is expected
     * and not a gap: Steadfast never says a parcel is in transit and RedEx
     * never says Unknown (ADR-0018).
     *
     * Deliberately a second transcription of the same documentation the
     * driver's own map is written from, and never read off the driver: two
     * independent readings of a Courier's status table are what catch a word
     * mistyped into one of them, which is the whole failure this is here for.
     *
     * @return list<string>
     */
    abstract public function rawStatusesFor(ShipmentStatus $status): array;

    /**
     * What this Courier calls a parcel that came back. It maps to Returned
     * and never to anything resembling the Order's own Cancelled — which is
     * asserted in each Courier's own words, because two of the three say
     * "cancelled" for exactly this.
     */
    public function returnedWording(): string
    {
        return $this->rawStatusesFor(ShipmentStatus::Returned)[0];
    }

    abstract public function supportsBulk(): bool;

    abstract public function supportsWebhook(): bool;

    abstract public function supportsBalance(): bool;

    abstract public function needsAreaResolution(): bool;

    abstract public function batchLimit(): int;
}
