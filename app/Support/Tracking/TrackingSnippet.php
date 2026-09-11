<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Order;
use App\Models\Page;
use App\Shortcodes\Definitions\CheckoutShortcode;

/**
 * The browser's half of the same events the outbox carries, as the one
 * `<script>` a page is written with.
 *
 * The two halves exist for each other: the server copy survives an ad
 * blocker, tracking prevention and an iOS restriction, and the browser copy
 * survives a broken cron and arrives in seconds rather than minutes. Both
 * carry the same deduplication id, so the Provider counts one event and not
 * two.
 *
 * What is built here is the element, the consent hook, the identifier a Page
 * load is deduplicated on, the beacons that carry what only the browser saw
 * to the server, and the choice of what fires and when. What goes inside it
 * is each Provider's own `script()` and `calls()`: one base code per
 * Destination, and nothing in this class ever names an event of any
 * platform's.
 *
 * Where a page reports and what it reports there is `TrackingPlans::forPage()`,
 * the same answer the recorder takes, so the two halves cannot disagree
 * about either.
 */
class TrackingSnippet
{
    /**
     * The one global a Merchant's cookie banner sets to hold the Pixel.
     *
     * Absent or true means fire, and only an explicit `false` holds it: a
     * hook defaulting to blocking would silently disable reporting for every
     * Merchant who never heard of it, which is nearly all of them, and this
     * market imposes no consent obligation to trade that for.
     */
    public const CONSENT_HOOK = 'gioTrackingConsent';

    /**
     * What the snippet calls the identifier the browser makes up for this
     * page load. A name inside the element's own closure rather than a
     * global: nothing outside reads it, and the Pixel and the beacon both
     * being handed the same one is the whole of the deduplication.
     */
    private const LOAD_IDENTIFIER = 'gioLoadId';

    /**
     * What the snippet calls the Selection a visitor currently has in front
     * of them, handed over by the Checkout island at the moment they start
     * filling the form.
     *
     * A name rather than a value for the reason the identifier above is one:
     * a published Page is one file served to everyone, and what a particular
     * visitor has ticked is not knowable when the document is written.
     */
    private const SELECTION = 'gioSelection';

    /**
     * Whether the Checkout Started Moment has already been reported for this
     * page load. A Moment that happens once per load is reported once per
     * load, whatever the form does afterwards.
     */
    private const STARTED = 'gioStarted';

    /**
     * Where the snippet inserts itself, in the order it tries.
     *
     * `<head>` first, because a Pixel is meant to fire before the page
     * paints; `</body>` for a Merchant who wrote a document with no head;
     * and the end of the bytes for one who wrote neither, which still runs.
     * Matched case-insensitively — `</HEAD>` is the same tag, and a
     * Merchant's document is a Merchant's document.
     */
    private const INSERTION_POINTS = ['</head>', '</body>'];

    public function __construct(private readonly TrackingPlans $plans) {}

    /**
     * The served document with this Page's Pixel in it.
     *
     * Injected rather than authored: a Page's body is the whole HTML
     * document and belongs to the Merchant, and a Merchant who forgets to
     * paste a Pixel is the failure this avoids. What this returns is both
     * what the visitor receives and what is frozen into the static cache, so
     * there is one document and not two.
     *
     * Nothing is caught here. The only thing that can fail is reading the
     * Destinations, and the Page lookup that reached this line already made
     * that connection — a catch would trade a loud failure for a cached Page
     * that silently reports nothing for as long as it stands.
     */
    public function injectedInto(string $html, Page $page): string
    {
        $snippet = $this->forPage($page);

        if ($snippet === '') {
            return $html;
        }

        foreach (self::INSERTION_POINTS as $tag) {
            $position = stripos($html, $tag);

            if ($position !== false) {
                return substr_replace($html, "\n".$snippet."\n", $position, 0);
            }
        }

        return $html."\n".$snippet;
    }

    /**
     * The Pixel a published Page carries: an identifier for this load, each
     * Destination's base code reporting the Visit, what to report should the
     * visitor start filling the Checkout, and the beacons that tell the
     * server the same things.
     *
     * Nothing at all where the Page reports nowhere. A Page with no
     * Destination is injected with nothing and serves the document it has
     * always served, byte for byte — no element, no global, no beacon and no
     * request to anyone.
     *
     * A Destination the Merchant has switched on but not yet given a dataset
     * still gets the beacon: the server half stands alone, and the rows it
     * writes are what the Merchant is owed once the credential is pasted.
     *
     * Each beacon is carried only where some Destination still reports the
     * Moment it is about. A Page whose Visit events are all switched off
     * would write no row for the load whatever the beacon said, and a
     * request that can only be discarded is not one to put on the path of an
     * ad click; the same holds for the listener a Checkout Started is
     * reported from.
     */
    public function forPage(Page $page): string
    {
        $plan = $this->plans->forPage($page);

        if ($plan->reportsNowhere()) {
            return '';
        }

        $scripts = [];
        $started = [];
        $reportsVisit = false;
        $reportsStart = false;

        foreach ($plan->destinations() as $destination) {
            $driver = $destination->driver();
            $events = $plan->eventsFor($destination, TrackingMoment::Visit);
            $starts = $plan->eventsFor($destination, TrackingMoment::CheckoutStarted);

            $reportsVisit = $reportsVisit || $events !== [];
            $reportsStart = $reportsStart || $starts !== [];

            $script = $driver->script(
                $destination,
                $this->visit($events),
                reportsLoad: $plan->reports($destination->provider, $driver->loadEvent()),
            );

            if ($script !== '') {
                $scripts[] = $script;
            }

            $calls = $driver->calls($destination, $this->checkoutStarted($starts, $plan->sendsContentDetail()));

            if ($calls !== '') {
                $started[] = $calls;
            }
        }

        /** No script to run and nothing to report is the document as it was. */
        if ($scripts === [] && ! $reportsVisit && ! $reportsStart) {
            return '';
        }

        return $this->element([
            $this->identifier(),

            /** The one sender both beacons go through, and nothing at all where neither is carried. */
            ...($reportsVisit || $reportsStart ? [$this->reporter()] : []),

            ...$scripts,

            /**
             * The listener is registered before the Visit is posted, so that
             * a visitor who reaches the form while the first beacon is still
             * in flight is not missed. It costs nothing to register early and
             * a Moment to register late.
             */
            ...($reportsStart ? [$this->whenCheckoutStarts($page, $started)] : []),

            ...($reportsVisit ? [$this->reportVisit($page)] : []),
        ]);
    }

    /**
     * The Pixel a page about one Order carries: the base code, which reports
     * the page's own load, and the Purchase where this browser is the one
     * that placed the Order.
     *
     * The Purchase is gated rather than fired on every load because the
     * address is a bearer handle — it is in browser history, in a Referer
     * and in a gateway's logs, and anyone holding the link can open it. Meta
     * deduplicates for only 48 hours, so a Pixel firing on every load would
     * report the Customer's own Purchase again the following week.
     *
     * The Destinations are the Order's Page's. A Page in the bin still
     * resolves, because the relation reads through it; a Page deleted for
     * good leaves the default Destination and the global event defaults,
     * which is what a Page that picked nothing has anyway.
     */
    public function forOrder(Order $order, bool $reportsPurchase): string
    {
        $plan = $this->plans->forPage($order->page);

        $scripts = [];

        foreach ($plan->destinations() as $destination) {
            $driver = $destination->driver();

            $script = $driver->script(
                $destination,
                $this->purchase($plan, $destination, $order, $reportsPurchase),
                reportsLoad: $plan->reports($destination->provider, $driver->loadEvent()),
            );

            if ($script !== '') {
                $scripts[] = $script;
            }
        }

        return $this->element($scripts);
    }

    /**
     * The Visit as the browser is told to report it: each event this Page
     * reports for the Moment — the load of the page among them — deduplicated
     * against the copy the beacon is about to write.
     *
     * @param  list<string>  $events
     * @return list<BrowserEvent>
     */
    private function visit(array $events): array
    {
        return array_map(
            fn (string $event): BrowserEvent => BrowserEvent::forLoad($event, self::LOAD_IDENTIFIER),
            $events,
        );
    }

    /**
     * The Checkout Started as the browser is told to report it: each event
     * this Page reports for the Moment, about whatever the visitor has ticked
     * at the moment they touch the first question, deduplicated against the
     * copy the beacon is about to write.
     *
     * The same identifier the load carries. A Moment that happens once per
     * page load is counted once per page load, and a Provider deduplicates an
     * event name against its own — so the load's own events and this one
     * sharing a string is not a collision, it is the two of them agreeing
     * which load they belong to.
     *
     * @param  list<string>  $events
     * @return list<BrowserEvent>
     */
    private function checkoutStarted(array $events, bool $contentDetail): array
    {
        return array_map(
            fn (string $event): BrowserEvent => BrowserEvent::forSelection(
                $event,
                self::LOAD_IDENTIFIER,
                self::SELECTION,
                $contentDetail,
            ),
            $events,
        );
    }

    /**
     * The Purchase this Destination reports for the Order, or nothing where
     * this browser is not the one that placed it.
     *
     * Which Moment the Purchase belongs to is `MomentRecorder`'s rule and not
     * restated here, and which events that Moment produces — less the ones
     * this Page does not report — is the Plan's answer, the same one the
     * outbox row was written from.
     *
     * @return list<BrowserEvent>
     */
    private function purchase(TrackingPlan $plan, Destination $destination, Order $order, bool $reportsPurchase): array
    {
        if (! $reportsPurchase) {
            return [];
        }

        return array_map(
            fn (string $event): BrowserEvent => BrowserEvent::forOrder($event, $order, $plan->sendsContentDetail()),
            $plan->eventsFor($destination, MomentRecorder::purchaseMomentFor($order)),
        );
    }

    /**
     * The identifier this page load is deduplicated on, made up by the
     * browser rather than written into the document: a published Page is one
     * file served to everyone, so a value baked in at render time would be
     * the same for every visitor and the Provider would count one Visit for
     * the whole campaign.
     *
     * `randomUUID` where the browser has it, and a timestamp with a random
     * tail where it does not — it is absent outside a secure context and in
     * the older in-app webviews a good share of this market browses from,
     * and an identifier only has to be unlike the last one.
     */
    private function identifier(): string
    {
        $name = self::LOAD_IDENTIFIER;

        return <<<JS
        var {$name} = (window.crypto && window.crypto.randomUUID)
            ? window.crypto.randomUUID()
            : 'v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
        JS;
    }

    /**
     * How the server is told anything at all: one sender, used by every
     * Moment the browser is the only witness to.
     *
     * `sendBeacon` first, because it survives the visitor leaving for the
     * next page, and a `keepalive` fetch for a browser without it. Both are
     * fire-and-forget: nothing waits on the answer, the answer is empty, and
     * a refusal — a rate limit, an outage — costs the redundancy and not the
     * event, because the Pixel has already fired.
     *
     * Nothing here may raise. `sendBeacon` returns false where a browser
     * declines and throws outright in a few, and this runs in the Merchant's
     * `<head>`: an unhandled raise would leave a red line in the console of
     * a page that is working, and stop the fallback from being tried at all.
     */
    private function reporter(): string
    {
        return <<<'JS'
        function gioReport(address) {
            var gioReported = false;

            try {
                gioReported = !!(navigator.sendBeacon && navigator.sendBeacon(address));
            } catch (e) {}

            if (!gioReported && window.fetch) {
                fetch(address, {method: 'POST', keepalive: true, credentials: 'omit'}).catch(function () {});
            }
        }
        JS;
    }

    /**
     * The server's copy of the same Visit, posted the moment the page runs.
     *
     * What it says rides in the address. Every alternative needs either an
     * API the older webviews here do not have (`URLSearchParams`) or a
     * content type `sendBeacon` will not set from a string, and three short
     * public values in a query string cost nothing.
     */
    private function reportVisit(Page $page): string
    {
        $address = $this->address($page, TrackingMoment::Visit);
        $name = self::LOAD_IDENTIFIER;

        return <<<JS
        var gioBeacon = {$address} + encodeURIComponent({$name});

        gioReport(gioBeacon);
        JS;
    }

    /**
     * What runs when the Checkout island says a visitor has started filling
     * the form: the Pixel's copy of the Moment, and the server's.
     *
     * The island raises the event and says what is ticked; nothing here
     * reads the form, and nothing in the form knows this is listening — which
     * is why the event is named where it is raised and read from there. A
     * Page whose Merchant never wrote a Checkout into it raises it never and
     * reports nothing, which is right: there was no Checkout to start.
     *
     * Once per page load, whatever the form does afterwards: a visitor who
     * leaves the form and comes back to it has not started a second checkout,
     * and the copy the beacon writes lands on the outbox's unique index
     * anyway.
     *
     * The Selection is taken as the island hands it over, and a detail that
     * is missing reads as an empty one rather than throwing: this runs inside
     * a listener on the Merchant's own page, and a raise here would be a red
     * line in the console of a page that is working.
     *
     * @param  list<string>  $scripts  each Provider's calls for the Moment
     */
    private function whenCheckoutStarts(Page $page, array $scripts): string
    {
        $address = $this->address($page, TrackingMoment::CheckoutStarted);
        $identifier = self::LOAD_IDENTIFIER;
        $selection = self::SELECTION;
        $started = self::STARTED;
        $event = $this->encode(CheckoutShortcode::STARTED_EVENT);
        $body = $this->indented($this->indented(implode("\n\n", $scripts)));

        return <<<JS
        var {$selection} = null;
        var {$started} = false;

        document.addEventListener({$event}, function (e) {
            if ({$started}) {
                return;
            }

            {$started} = true;
            {$selection} = (e && e.detail) || {value: 0, count: 0, lines: []};

        {$body}

            gioReport({$address} + encodeURIComponent({$identifier}) + '&items=' + encodeURIComponent(
                ({$selection}.lines || []).map(function (l) { return l.id + ':' + l.quantity; }).join(',')
            ));
        });
        JS;
    }

    /**
     * Where a Moment is reported to, up to but not including the identifier
     * it is deduplicated on — which the browser makes up and appends itself.
     */
    private function address(Page $page, TrackingMoment $moment): string
    {
        return $this->encode(route('tracking.report').'?'.http_build_query([
            'slug' => $page->slug,
            'moment' => $moment->value,
        ]).'&id=');
    }

    /**
     * Every Provider's script in one element, behind the consent hook.
     *
     * Nothing at all where no Destination had anything to say, so a page with
     * no tracking configured is the document it has always been — no element,
     * no global, no request to anyone.
     *
     * @param  list<string>  $scripts
     */
    private function element(array $scripts): string
    {
        if ($scripts === []) {
            return '';
        }

        $hook = self::CONSENT_HOOK;
        $body = $this->indented(implode("\n\n", $scripts));

        return <<<HTML
        <script>
        (function () {
            if (window.{$hook} === false) {
                return;
            }

        {$body}
        })();
        </script>
        HTML;
    }

    /**
     * A Provider's script, moved in one step to sit under the guard around
     * it. A generated document is still read by people — the Merchant's own
     * browser is where a Pixel is debugged.
     */
    private function indented(string $script): string
    {
        return implode("\n", array_map(
            fn (string $line): string => $line === '' ? '' : '    '.$line,
            explode("\n", $script),
        ));
    }

    /**
     * A value as JavaScript source, under the flags Blade's `@json` uses, so
     * that nothing a Merchant named can end the element it is written into.
     */
    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
