<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use Illuminate\Http\Client\Response;

/**
 * What a Courier said when it was asked where one parcel stands.
 *
 * The distinction that matters is the one `Delivery` draws for an ad
 * platform, and for the same reason (ADR-0019). A Courier that answered has
 * told us something to write down, whether that is where the parcel is or
 * that it will not account for it. A Courier that could not be reached, or
 * that answered 5xx, or that would not accept this installation's
 * credentials, has told us nothing at all — so the Shipment is left exactly
 * as it was and the next sweep asks again.
 *
 * A poll answers rather than throws, unlike a booking: a sweep runs over
 * every parcel the couriers are carrying, and one that could not be asked
 * about is an outcome to record and walk on from rather than an exception to
 * unwind a request with.
 */
final class ShipmentProgress
{
    /**
     * @param  list<ReportedStatus>  $reported  What the Courier said about the parcel, oldest first, the last being where it stands now. Empty where the Courier has nothing to report yet.
     * @param  array<string, mixed>  $response  The Courier's answer as it arrived.
     */
    private function __construct(
        public readonly bool $answered,
        public readonly bool $isFinal,
        public readonly array $reported,
        public readonly ?string $error,
        public readonly array $response,
    ) {}

    /**
     * The Courier said where the parcel stands — in one word, or in the whole
     * history it keeps of the parcel, which is RedEx.
     *
     * @param  list<ReportedStatus>  $reported
     * @param  array<string, mixed>  $response
     */
    public static function reported(array $reported, array $response = []): self
    {
        return new self(answered: true, isFinal: true, reported: $reported, error: null, response: $response);
    }

    /**
     * The Courier answered and would not account for this parcel — it knows
     * no such consignment, or it refused the question in its own words.
     *
     * An answer, and about this parcel: it is written down, because a parcel
     * the Courier denies holding is exactly the parcel an operator has to
     * ring somebody about, and a sweep that swallowed it would leave the
     * Shipment sitting at whatever it last said for a month.
     *
     * @param  array<string, mixed>  $response
     */
    public static function refused(string $error, array $response = []): self
    {
        return new self(answered: false, isFinal: true, reported: [], error: $error, response: $response);
    }

    /**
     * The Courier could not be asked, or could not be asked by this
     * installation: a connection that would not open, a 5xx, a credential it
     * would not accept.
     *
     * Nothing is concluded and nothing at all is written — not even that the
     * parcel was asked about — so the next sweep asks again rather than
     * waiting out an interval on the strength of a call that failed.
     */
    public static function unreachable(string $error): self
    {
        return new self(answered: false, isFinal: false, reported: [], error: $error, response: []);
    }

    /**
     * Where the Courier says the parcel stands now: the last thing it
     * reported, or null where it reported nothing.
     */
    public function latest(): ?ReportedStatus
    {
        return $this->reported === [] ? null : $this->reported[array_key_last($this->reported)];
    }

    /**
     * The silence in this answer, where it amounts to one, or null where the
     * Courier was answering about the parcel and its driver must read what it
     * said.
     *
     * The one reading of "settles nothing", shared because it is one rule and
     * not three: a 5xx is a Courier having a bad morning, and a rejected
     * credential is a Merchant's Settings rather than anything about this
     * parcel. Writing either onto a parcel's timeline would put the whole
     * installation's trouble on every Shipment it holds (ADR-0019).
     */
    public static function silenceFrom(Response $response, Courier $courier): ?self
    {
        $label = $courier->getLabel();

        return match (true) {
            $response->serverError() => self::unreachable("{$label} answered {$response->status()} and said nothing about the parcel."),
            $response->unauthorized() || $response->forbidden() => self::unreachable("{$label} would not accept this installation's credentials (HTTP {$response->status()})."),
            default => null,
        };
    }
}
