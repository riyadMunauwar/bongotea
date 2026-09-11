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
 * that produces, the sweep hands it batches to deliver, and the snippet
 * builder asks it what the browser should run. Adding TikTok is one class
 * implementing this and one case on `TrackingProvider` — the outbox, the
 * sweep, the beacon and the Tracking tab are untouched.
 *
 * The questions are deliberately narrow. Nothing here takes a Page, a
 * Request or a Settings group: a driver reads the row or the Destination it
 * is handed and the Order that points at, and everything else is decided
 * before it is called.
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
     * The browser's half: this Destination's base code, which reports the
     * load of the page it is written into, followed by one call per event
     * given.
     *
     * The script and nothing around it — the element, the consent hook and
     * the choice of what fires are `TrackingSnippet`'s, so that a Provider
     * stays the only thing that ever speaks its own vocabulary.
     *
     * Empty where the browser has nothing to address this Destination by. A
     * base code with no public identifier is dead script on every page it is
     * baked into, and the access token is not asked for: that is the server
     * half's credential, and a Merchant who has pasted the identifier and
     * not yet the token is already reporting from the browser.
     *
     * @param  list<BrowserEvent>  $events
     */
    public function script(Destination $destination, array $events): string;

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
