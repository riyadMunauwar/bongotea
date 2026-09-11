<?php

namespace App\Support\Tracking;

use App\Models\Order;

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
 * What is built here is the element, the consent hook and the choice of what
 * fires. What goes inside it is each Provider's own `script()`: one base
 * code per Destination, and nothing in this class ever names an event of any
 * platform's.
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

    public function __construct(private readonly TrackingDestinations $destinations) {}

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
        $body = $this->indented(implode("\n", $scripts));

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
}
