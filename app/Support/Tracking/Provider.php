<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\TrackingEvent;

/**
 * What every ad platform is behind.
 *
 * One contract so that nothing outside this namespace speaks any platform's
 * vocabulary: a Moment is recorded, the Provider says which of its own events
 * that produces, the sweep hands it batches to deliver, and the snippet
 * builder asks it what the browser should run. Adding TikTok is one class
 * implementing this, one case on `TrackingProvider` and its properties on
 * `TrackingSettings` — the outbox, the sweep and the beacon are untouched.
 *
 * The questions are deliberately narrow. Nothing here takes a Page or a
 * Request: a driver reads the row it is handed, the Order that row points at,
 * and the account the Merchant supplied for it in `TrackingSettings`, and
 * everything else is decided before it is called.
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
     * The click identifiers this Provider matches a Moment to an ad click
     * by, under the names the browser hands them over as.
     *
     * The one place those names are written down: the Checkout keeps only
     * what some Provider recognises, the outbox's bag is keyed by them, and
     * a driver reads back the ones it knows. Adding TikTok adds `ttclid`
     * here and nowhere else.
     *
     * @return list<string>
     */
    public function clickIdNames(): array;

    /**
     * This Provider's own name for the load of a page, which its base code
     * reports by itself.
     *
     * Asked so that a caller can say whether that load is one of the events
     * the Merchant still wants reported, without ever having to spell the
     * name: a Page that has switched the load off must not have it fired out
     * from under it by a base code nobody read.
     */
    public function loadEvent(): string;

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
     * Why this Provider cannot be delivered to with what the Merchant supplied,
     * worded for the Merchant, or null where it can.
     *
     * The one wording of "usable", as `PaymentSettings::refusal()` is for a
     * Payment Method: the sweep prints it and the admin prints it, and
     * neither restates the rule.
     */
    public function refusal(): ?string;

    /**
     * The browser's half: this Provider's base code, which reports the
     * load of the page it is written into, followed by one call per event
     * given.
     *
     * Where the events given already include the Provider's own name for
     * that load, it is reported once and with its deduplication id rather
     * than twice: a Provider knows which of its own events its base code
     * fires, and a caller writing a server copy of one may not end up with
     * the load counted twice.
     *
     * The script and nothing around it — the element, the consent hook and
     * the choice of what fires are `TrackingSnippet`'s, so that a Provider
     * stays the only thing that ever speaks its own vocabulary.
     *
     * Empty where the browser has nothing to address this Provider by. A
     * base code with no public identifier is dead script on every page it is
     * baked into, and the access token is not asked for: that is the server
     * half's credential, and a Merchant who has pasted the identifier and
     * not yet the token is already reporting from the browser.
     *
     * The load is reported by the base code only where the caller says this
     * page's load is reported at all. A Merchant who has switched that event
     * off has switched it off everywhere, including the one copy a base code
     * makes without being asked.
     *
     * @param  list<BrowserEvent>  $events
     */
    public function script(array $events, bool $reportsLoad): string;

    /**
     * The same events with no base code around them, for a Moment the page
     * reports after it has loaded.
     *
     * A visitor starting the Checkout is such a Moment: the Pixel was
     * initialised when the page ran, and what happens later is the telling
     * alone. The caller runs this where the Moment happens; when that is, and
     * what it hands the events to report about, is `TrackingSnippet`'s and
     * never a Provider's.
     *
     * Empty for a Provider the browser cannot address, exactly as
     * `script()` is.
     *
     * @param  list<BrowserEvent>  $events
     */
    public function calls(array $events): string;

    /**
     * One outbox row as this Provider's wire format: exactly what `send()`
     * puts in a batch for it.
     *
     * Asked separately from sending so that a Merchant can be shown what the
     * store will report before it reports anything — a preview builds a
     * sample row and prints this, rather than describing the payload a
     * second time and drifting from it. It reads the row it is
     * handed and the Order that row points at, and nothing else, so a row
     * that was never saved previews as truly as one that was.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(TrackingEvent $event): array;

    /**
     * Deliver one batch to the account the Merchant supplied, in a single request.
     *
     * The batch is never larger than `batchLimit()` and every row in it is
     * for this Provider. A driver makes exactly one call and answers what
     * happened; it never retries in-request, because the sweep is the retry.
     *
     * @param  list<TrackingEvent>  $events
     */
    public function send(array $events): Delivery;
}
