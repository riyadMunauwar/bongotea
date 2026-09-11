<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Models\Order;
use App\Models\Page;

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
 * load is deduplicated on, the beacon that carries that load to the server,
 * and the choice of what fires. What goes inside it is each Provider's own
 * `script()`: one base code per Destination, and nothing in this class ever
 * names an event of any platform's.
 *
 * Which Destinations a page reports to is `TrackingDestinations::forPage()`,
 * the same answer the recorder takes, so the two halves cannot disagree
 * about where a Page reports.
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
     * Where the snippet inserts itself, in the order it tries.
     *
     * `<head>` first, because a Pixel is meant to fire before the page
     * paints; `</body>` for a Merchant who wrote a document with no head;
     * and the end of the bytes for one who wrote neither, which still runs.
     * Matched case-insensitively — `</HEAD>` is the same tag, and a
     * Merchant's document is a Merchant's document.
     */
    private const INSERTION_POINTS = ['</head>', '</body>'];

    public function __construct(private readonly TrackingDestinations $destinations) {}

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
     * Destination's base code reporting the Visit, and the beacon that tells
     * the server the same thing.
     *
     * Nothing at all where the Page reports nowhere. A Page with no
     * Destination is injected with nothing and serves the document it has
     * always served, byte for byte — no element, no global, no beacon and no
     * request to anyone.
     *
     * A Destination the Merchant has switched on but not yet given a dataset
     * still gets the beacon: the server half stands alone, and the rows it
     * writes are what the Merchant is owed once the credential is pasted.
     */
    public function forPage(Page $page): string
    {
        $destinations = $this->destinations->forPage($page);

        if ($destinations === []) {
            return '';
        }

        $scripts = [];

        foreach ($destinations as $destination) {
            $driver = $destination->driver();

            $script = $driver->script($destination, $this->visit($driver));

            if ($script !== '') {
                $scripts[] = $script;
            }
        }

        return $this->element([$this->identifier(), ...$scripts, $this->beacon($page)]);
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
        $scripts = [];

        foreach ($this->destinations->forPage($order->page) as $destination) {
            $driver = $destination->driver();

            $script = $driver->script($destination, $this->purchase($driver, $order, $reportsPurchase));

            if ($script !== '') {
                $scripts[] = $script;
            }
        }

        return $this->element($scripts);
    }

    /**
     * The Visit, as this Destination's Provider reports it: every event the
     * Moment produces — the load of the page among them — each deduplicated
     * against the copy the beacon is about to write.
     *
     * @return list<BrowserEvent>
     */
    private function visit(Provider $driver): array
    {
        return array_map(
            fn (string $event): BrowserEvent => BrowserEvent::forLoad($event, self::LOAD_IDENTIFIER),
            $driver->eventsFor(TrackingMoment::Visit),
        );
    }

    /**
     * The Purchase this Destination reports for the Order, or nothing where
     * this browser is not the one that placed it.
     *
     * Which Moment the Purchase belongs to is `MomentRecorder`'s rule and not
     * restated here, and which events that Moment produces is the Provider's
     * answer — the seam a Page's own event switches filter at.
     *
     * @return list<BrowserEvent>
     */
    private function purchase(Provider $driver, Order $order, bool $reportsPurchase): array
    {
        if (! $reportsPurchase) {
            return [];
        }

        return array_map(
            fn (string $event): BrowserEvent => BrowserEvent::forOrder($event, $order),
            $driver->eventsFor(MomentRecorder::purchaseMomentFor($order)),
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
     * The server's copy of the same Visit, posted the moment the page runs.
     *
     * `sendBeacon` first, because it survives the visitor leaving for the
     * next page, and a `keepalive` fetch for a browser without it. Both are
     * fire-and-forget: nothing waits on the answer, the answer is empty, and
     * a refusal — a rate limit, an outage — costs the redundancy and not the
     * event, because the Pixel above has already fired.
     *
     * What it says rides in the address. Every alternative needs either an
     * API the older webviews here do not have (`URLSearchParams`) or a
     * content type `sendBeacon` will not set from a string, and three short
     * public values in a query string cost nothing.
     *
     * Nothing here may raise. `sendBeacon` returns false where a browser
     * declines and throws outright in a few, and this runs in the Merchant's
     * `<head>`: an unhandled raise would leave a red line in the console of
     * a page that is working, and stop the fallback from being tried at all.
     */
    private function beacon(Page $page): string
    {
        $address = $this->encode(route('tracking.report').'?'.http_build_query([
            'slug' => $page->slug,
            'moment' => TrackingMoment::Visit->value,
        ]).'&id=');

        $name = self::LOAD_IDENTIFIER;

        return <<<JS
        var gioBeacon = {$address} + encodeURIComponent({$name});
        var gioReported = false;

        try {
            gioReported = !!(navigator.sendBeacon && navigator.sendBeacon(gioBeacon));
        } catch (e) {}

        if (!gioReported && window.fetch) {
            fetch(gioBeacon, {method: 'POST', keepalive: true, credentials: 'omit'}).catch(function () {});
        }
        JS;
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
