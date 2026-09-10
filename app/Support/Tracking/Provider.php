<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\Destination;
use App\Models\TrackingEvent;

/**
 * What every ad platform is behind.
 *
 * One contract so that nothing outside this namespace speaks any platform's
 * vocabulary: a Moment is recorded, the Provider says which of its own events
 * that produces, and the sweep hands it batches to deliver. Adding TikTok is
 * one class implementing this and one case on `TrackingProvider` — the
 * outbox, the sweep, the beacon and the Tracking tab are untouched.
 *
 * The four questions are deliberately narrow. Nothing here takes a Page, a
 * Request or a Settings group: a driver reads the row it is handed and the
 * Order that row points at, and everything else is decided before it is
 * called.
 */
interface Provider
{
    public function provider(): TrackingProvider;

    /**
     * The Provider's own events one Moment produces, in the order they are
     * reported. Empty where this Provider has nothing to say about a Moment,
     * which is how a Provider opts out of one without a list anywhere else.
     *
     * @return list<string>
     */
    public function eventsFor(TrackingMoment $moment): array;

    /**
     * How many events one request to this Provider may carry. The sweep cuts
     * its batches here.
     */
    public function batchLimit(): int;

    /**
     * How long after a Moment happened this Provider still accepts it. A row
     * older than this can never serve its purpose again and is Expired rather
     * than retried forever.
     */
    public function acceptanceWindowSeconds(): int;

    /**
     * Why this Destination cannot be delivered to, worded for the Merchant,
     * or null where it can.
     *
     * The one wording of "usable", as `PaymentSettings::refusal()` is for a
     * Payment Method: the sweep prints it and the admin prints it, and
     * neither restates the rule.
     */
    public function refusal(Destination $destination): ?string;

    /**
     * Deliver one batch to one Destination, in a single request.
     *
     * The batch is never larger than `batchLimit()` and every row in it is
     * for this Destination. A driver makes exactly one call and answers what
     * happened; it never retries in-request, because the sweep is the retry.
     *
     * @param  list<TrackingEvent>  $events
     */
    public function send(Destination $destination, array $events): Delivery;
}
