<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
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
 * What is built here is the element and the consent hook. What goes inside it
 * is each Provider's own `script()`: one base code per Provider, and
 * nothing in this class ever names an event of any platform's.
 *
 * Where the store reports and what it reports there is
 * `TrackingPlans::current()`, the same answer the recorder takes, so the two
 * halves cannot disagree about either.
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
     * The global the storefront's Pixel is reached through: one function per
     * Moment the browser reports, keyed by the Moment, each taking the
     * deduplication id and the Selection the storefront worked out.
     */
    public const MOMENTS = 'bongoteaMoments';

    /** What a storefront Moment's function calls its deduplication id and its Selection. */
    private const IDENTIFIER = 'id';

    private const SELECTION = 'selection';

    public function __construct(
        private readonly TrackingPlans $plans,
        private readonly VisitCounter $visits,
    ) {}

    /**
     * The Pixel every storefront page carries, written into the Inertia root
     * document: each Provider's base code, and one function per Moment the
     * browser reports for the storefront to call as each one happens.
     *
     * The load is not reported by the base code. The storefront is one
     * document across every visit a browser makes inside it, so it reports a
     * Visit itself on each one, under an identifier the beacon carries too —
     * a bare `PageView` from the base code would be a second, undeduplicated
     * copy of the first.
     *
     * Every Moment here is about something only the browser knows when it
     * happens, so its deduplication id and its Selection are the arguments
     * the storefront passes in, never values written into the document.
     */
    public function forStorefront(): string
    {
        $plan = $this->plans->current();

        $scripts = [];
        $calls = [];

        foreach ($plan->providers() as $provider) {
            $driver = $provider->driver();
            $script = $driver->script([], reportsLoad: false);

            if ($script === '') {
                continue;
            }

            $scripts[] = $script;

            foreach (TrackingMoment::reportableByBrowser() as $moment) {
                $call = $driver->calls($this->storefrontEvents($plan, $provider, $moment));

                if ($call !== '') {
                    $calls[$moment->value][] = $call;
                }
            }
        }

        if ($scripts === []) {
            return '';
        }

        return $this->element([...$scripts, $this->momentsGlobal($calls)]);
    }

    /**
     * Where the storefront posts the server's copy of a Moment, or null where
     * a beacon would write nothing: the store reports nowhere and counts no
     * Visits. The beacon costs a request on every load, and a store asking
     * for neither is not made to pay it.
     */
    public function beacon(): ?string
    {
        return $this->plans->current()->reportsNowhere() && ! $this->visits->isCounting()
            ? null
            : route('tracking.report');
    }

    /**
     * The events this Provider reports for one storefront Moment, less the
     * ones the store has switched off.
     *
     * @return list<BrowserEvent>
     */
    private function storefrontEvents(TrackingPlan $plan, TrackingProvider $provider, TrackingMoment $moment): array
    {
        return array_map(
            fn (string $event): BrowserEvent => $moment->isAboutProductsNamed()
                ? BrowserEvent::forSelection($event, self::IDENTIFIER, self::SELECTION, $plan->sendsContentDetail())
                : BrowserEvent::forLoad($event, self::IDENTIFIER),
            $plan->eventsFor($provider, $moment),
        );
    }

    /**
     * The global the storefront calls into: a function per Moment that has
     * anything to report, each running every Provider's calls for it.
     *
     * @param  array<string, list<string>>  $calls  by Moment
     */
    private function momentsGlobal(array $calls): string
    {
        $functions = [];

        foreach ($calls as $moment => $bodies) {
            $body = implode("\n", array_map(fn (string $line): string => '        '.$line, explode("\n", implode("\n", $bodies))));
            $parameters = self::IDENTIFIER.', '.self::SELECTION;

            $functions[] = "    \"{$moment}\": function ({$parameters}) {\n{$body}\n    }";
        }

        $global = self::MOMENTS;

        return $functions === []
            ? "window.{$global} = {};"
            : "window.{$global} = {\n".implode(",\n", $functions)."\n};";
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
     */
    public function forOrder(Order $order, bool $reportsPurchase): string
    {
        $plan = $this->plans->current();

        $scripts = [];

        foreach ($plan->providers() as $provider) {
            $driver = $provider->driver();

            $script = $driver->script(
                $this->purchase($plan, $provider, $order, $reportsPurchase),
                reportsLoad: $plan->reports($provider, $driver->loadEvent()),
            );

            if ($script !== '') {
                $scripts[] = $script;
            }
        }

        return $this->element($scripts);
    }

    /**
     * The Purchase this Provider reports for the Order, or nothing where
     * this browser is not the one that placed it.
     *
     * Which Moment the Purchase belongs to is `MomentRecorder`'s rule and not
     * restated here, and which events that Moment produces — less the ones
     * the store does not report — is the Plan's answer, the same one the
     * outbox row was written from.
     *
     * @return list<BrowserEvent>
     */
    private function purchase(TrackingPlan $plan, TrackingProvider $provider, Order $order, bool $reportsPurchase): array
    {
        if (! $reportsPurchase) {
            return [];
        }

        return array_map(
            fn (string $event): BrowserEvent => BrowserEvent::forOrder($event, $order, $plan->sendsContentDetail()),
            $plan->eventsFor($provider, MomentRecorder::purchaseMomentFor($order)),
        );
    }

    /**
     * Every Provider's script in one element, behind the consent hook.
     *
     * Nothing at all where no Provider had anything to say, so a page with
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
}
