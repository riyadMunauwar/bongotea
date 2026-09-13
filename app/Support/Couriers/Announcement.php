<?php

namespace App\Support\Couriers;

use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * What a Courier said, on its own, about a parcel: read off a webhook to find
 * the Shipment it names, and for nothing else that could move state.
 *
 * The courier twin of `Notification`, and held to the same rule for the same
 * reason (ADR-0019). It arrived on an unauthenticated POST to an address
 * anyone can guess — RedEx's only credential is a token in a query string,
 * which lands in access logs and proxies — so what it claims happened is not
 * believed: the system asks the Courier itself and writes down what its own
 * call learned.
 *
 * One bounded exception, which is the whole of why this carries a second
 * field. Pathao narrates a parcel in prose that exists nowhere else — there
 * is no endpoint to ask whether "Package arrived at the sorting center" was
 * ever said — so a message with no state behind it is written to the timeline
 * as text. It can add a noise line; it can move nothing.
 *
 * `claimedStatus` is therefore never translated and never written. It is the
 * Courier's own word, kept for two things and no third: deciding that a call
 * of ours is worth making, and naming what an announcement claimed in the
 * log when it turns out to name no parcel we hold.
 */
final class Announcement
{
    /**
     * @param  string  $invoiceReference  Our reference for the attempt, the one the Courier was told at booking and echoes back.
     * @param  string|null  $claimedStatus  The Courier's own word for what it says has happened, where the announcement claims one. Logged, never believed.
     * @param  string|null  $narration  A line of text with no state behind it, where the announcement carries one.
     * @param  DateTimeInterface|string|null  $occurredAt  The Courier's own time, where it gave one.
     */
    public function __construct(
        public readonly string $invoiceReference,
        public readonly ?string $claimedStatus = null,
        public readonly ?string $narration = null,
        public readonly DateTimeInterface|string|null $occurredAt = null,
    ) {}

    /**
     * Whether this announcement says the parcel's state has moved — in which
     * case the payload is done with, and our own outbound call decides.
     *
     * A claim takes precedence over anything else the same body carried: an
     * announcement that says where the parcel is has already told us all we
     * are going to read off it, and the narration beside it would be a second
     * telling of a happening our own call is about to record properly.
     */
    public function claimsAStatusChange(): bool
    {
        return $this->claimedStatus !== null;
    }

    /** Whether there is a line of narration to write, and no state claimed to override it. */
    public function narrates(): bool
    {
        return ! $this->claimsAStatusChange() && filled($this->narration);
    }

    /**
     * One field off an announcement, as a word or not at all.
     *
     * Here rather than on each driver because reading a scalar off a request
     * is not one of the asymmetries the couriers disagree about — which
     * fields to read is, and that stays on the driver. Total, like every
     * reading of something that arrived over the wire, and blank-proof: an
     * empty string is a field the Courier did not fill in, and reading it as
     * a claim would send the system asking about a parcel nothing named.
     */
    public static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_scalar($value) && filled(trim((string) $value)) ? trim((string) $value) : null;
    }
}
