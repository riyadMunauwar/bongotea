<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

    /**
     * Fake the Courier being unreachable for every call.
     *
     * The one thing every Courier does identically, because silence has no
     * vocabulary: a connection that will not open is the same fact whoever was
     * being called, and it reaches the driver as the same exception. A Courier
     * with a stranger outage overrides this.
     */
    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
    }

    /** Assert a booking went out for this Shipment carrying every field the Courier calls mandatory, filled. */
    abstract public function assertBookedFor(Shipment $shipment): void;

    /**
     * What this Courier asks about a parcel beyond the recipient, by the name
     * its driver declares, and the value `makeUsable()` left in Settings as
     * the installation's default for it.
     *
     * What the send dialog should open pre-filled with. Empty for a Courier
     * that asks nothing.
     *
     * @return array<string, string>
     */
    abstract public function parcelDefaults(): array;

    /**
     * The same questions answered differently, as a User overriding them for
     * one unusual parcel would. Every value differs from `parcelDefaults()`.
     *
     * @return array<string, string>
     */
    abstract public function parcelOverrides(): array;

    /**
     * Assert the booking that went out carried these answers to this Courier's
     * own questions, in the Courier's own field names.
     *
     * @param  array<string, string>  $values
     */
    abstract public function assertBookedWithParcelDetails(array $values): void;

    /**
     * The whole of what is written onto the attempt when the User answered
     * this Courier's questions this way — their answers, plus whatever this
     * Courier asks that nobody is ever shown, in the order the driver declares
     * them.
     *
     * Usually just the answers back. It is a question at all because a
     * question with one possible answer is left out of the dialog and still
     * sent (`ParcelField::isWorthAsking()`), so a Merchant with one pickup
     * store has a row holding a field they were never asked about — and what
     * the row says was sent must be exactly what was sent, no more and no
     * fewer keys.
     *
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    public function parcelRowFor(array $answers): array
    {
        return $answers;
    }

    /**
     * How many pickup stores this Courier gives the Merchant a choice between
     * on an installation `makeUsable()` has set up.
     *
     * Zero for a Courier with no notion of a store at all, one for a Merchant
     * with a single warehouse — and either way nobody is asked which store the
     * parcel leaves from, which is what this is here to prove.
     */
    abstract public function pickupStoreCount(): int;

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
