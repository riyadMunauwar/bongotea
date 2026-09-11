<?php

use App\Enums\TrackingMoment;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Shortcodes\Definitions\CheckoutShortcode;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Settings\TrackingSettings;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * A visitor who starts filling in the Checkout, reported from both halves.
 *
 * The Moment that happens between a load and an Order, and the only one whose
 * money nobody has written down yet: there is no Order to read a basket off,
 * so what the visitor has ticked has to travel from the form to the Pixel and
 * to the beacon, and the server has to price it from the Page rather than
 * believe what it is told. Everything below is read out of the document a
 * visitor is actually served, the rows the beacon actually writes, or the
 * request the sweep actually sends.
 */

/**
 * A published Page selling these Products and reporting to this Provider's
 * Destination.
 *
 * @param  array<string, mixed>  $checkout  what this Page's Checkout Config says, over its defaults
 */
function startingPage(ProviderScript $script, Product|iterable $products, array $checkout = []): Page
{
    $script->makeDestination();

    $page = sellingPage($products);

    return $checkout === []
        ? $page
        : tap($page)->update(['checkout_config' => CheckoutConfig::fromArray([
            ...$page->checkout_config->toArray(),
            ...$checkout,
        ])]);
}

/**
 * The browser reporting that a visitor has started filling the Checkout, the
 * way the snippet does it: everything in the address, nothing in the body,
 * and the header a browser sets itself.
 *
 * The items ride as `id:quantity` pairs, which is the whole of what the
 * browser is trusted to say — the prices are the Page's.
 */
function reportStart(Page $page, ?string $items = null, string $identifier = 'load-3f9a2c'): TestResponse
{
    $query = array_filter([
        'slug' => $page->slug,
        'moment' => TrackingMoment::CheckoutStarted->value,
        'id' => $identifier,
        'items' => $items,
    ], fn (?string $value): bool => $value !== null);

    return test()->post(route('tracking.report').'?'.http_build_query($query), [], ['Sec-Fetch-Site' => 'same-origin']);
}

/**
 * The one event a served document reports for the Moment, read back out of
 * the bytes.
 *
 * @return array<string, mixed>
 */
function checkoutStartedIn(ProviderScript $script, string $html): array
{
    return collect($script->browserEventsIn($html))
        ->sole(fn (array $event): bool => in_array($script->eventNameOf($event), $script->checkoutStartedEvents(), true));
}

describe('what a served page tells the browser to report', function () {
    /**
     * The Moment happens after the page has loaded and only if the visitor
     * reaches the form, so the Pixel is told about it from a listener rather
     * than on the way down the document. A page nobody fills in reports the
     * Visit and nothing else.
     */
    it('reports the moment when the visitor starts filling the form, and not on load', function (ProviderScript $script) {
        $html = $this->get('/'.startingPage($script, Product::factory()->create())->slug)->assertOk()->getContent();

        expect(array_map($script->eventNameOf(...), $script->browserEventsIn(onLoad($html))))
            ->toBe($script->visitEvents())
            ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))
            ->toBe([...$script->visitEvents(), ...$script->checkoutStartedEvents()]);
    })->with('tracking providers');

    /**
     * One file is served to every visitor, so what a particular one has
     * ticked cannot be written into it: the Pixel is handed an expression
     * over the Selection the Checkout island reports, exactly as the
     * identifier a load is deduplicated on is handed over as a name.
     */
    it('works out what it reports at the moment it fires, from what the island hands it', function (ProviderScript $script) {
        $html = $this->get('/'.startingPage($script, Product::factory()->create())->slug)->assertOk()->getContent();

        $started = checkoutStartedIn($script, $html);
        $selection = startedBeaconIn($html)['selection'];

        expect($script->reportsLiveSelection($started))->toBeTrue()
            ->and($script->namesWhatWasBought($started))->toBeTrue()

            /** And what it reads is what the island said, rather than anything read off the form here. */
            ->and($html)->toContain($selection.' = (e && e.detail)')
            ->and($html)->toContain('document.addEventListener("'.CheckoutShortcode::STARTED_EVENT.'"');
    })->with('tracking providers');

    /**
     * The Moment happens once per page load, so it is deduplicated on the
     * identifier that load already made up — a Provider counts an event
     * against its own name, so sharing the string with the load's own events
     * is the two of them agreeing which load they belong to.
     */
    it('counts one checkout started per page load, under the identifier the load carries', function (ProviderScript $script) {
        $html = $this->get('/'.startingPage($script, Product::factory()->create())->slug)->assertOk()->getContent();

        $beacon = startedBeaconIn($html);

        expect($beacon['identifier'])->toBe(beaconIn($html)['identifier'])
            ->and($script->deduplicationIdOf(checkoutStartedIn($script, $html)))->toBe($beacon['identifier'])

            /** And a form touched again later says nothing a second time. */
            ->and($html)->toContain('if (gioStarted) {');
    })->with('tracking providers');

    /**
     * The same suppression the server copy obeys, because the two copies of
     * one event may not disagree about what it was — a suppression least of
     * all.
     */
    it('names nothing bought where the page withholds its content detail', function (ProviderScript $script) {
        $page = startingPage($script, Product::factory()->create());

        $page->update(['tracking_config' => ['suppress_content_detail' => true]]);

        $started = checkoutStartedIn($script, $this->get('/'.$page->slug)->assertOk()->getContent());

        expect($script->reportsLiveSelection($started))->toBeTrue()
            ->and($script->namesWhatWasBought($started))->toBeFalse();
    })->with('tracking providers');

    /**
     * A Page whose Merchant switched the event off carries no listener and no
     * beacon for it: a request that could only be discarded is not one to put
     * on the path of a visitor filling in a form.
     */
    it('carries nothing at all where the page has switched the event off', function (ProviderScript $script) {
        $page = startingPage($script, Product::factory()->create());

        $page->update(['tracking_config' => [
            'events' => [$script->provider()->value => array_fill_keys($script->checkoutStartedEvents(), false)],
        ]]);

        $html = $this->get('/'.$page->slug)->assertOk()->getContent();

        /**
         * The island still raises the event — it knows nothing about
         * tracking, and a Page with no Pixel raises it into nothing. What is
         * gone is anything listening for it.
         */
        expect($html)->not->toContain('document.addEventListener("'.CheckoutShortcode::STARTED_EVENT.'"')
            ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe($script->visitEvents());
    })->with('tracking providers');
});

describe('what the checkout island says about itself', function () {
    /**
     * The Moment is "a visitor touched a Checkout Field", so the questions
     * are marked and nothing else on the form is. Ticking a Product or
     * stepping a quantity is arranging an order rather than filling in the
     * form, and must not count as starting one.
     */
    it('marks every question a question, and marks nothing else', function () {
        $page = sellingPage(Product::factory()->create());

        $html = $this->get('/'.$page->slug)->assertOk()->getContent();

        /** The attribute as it is written on a wrapper, not as the script looks one up. */
        expect(substr_count($html, 'data-gio-field>'))->toBe(count($page->checkout_config->enabledFields()))
            ->and($html)->toContain('data-gio-line data-product-id=');
    });

    /**
     * Raised on the document and once per page load. The island knows how a
     * Checkout is drawn and nothing about who wants to hear this; a Page with
     * no Pixel on it raises the same event into nothing.
     */
    it('raises the moment once, and says what is ticked as it does', function () {
        $html = $this->get('/'.sellingPage(Product::factory()->create())->slug)->assertOk()->getContent();

        expect($html)->toContain('new CustomEvent(CHECKOUT_STARTED, { detail: selectionOf(form) })')
            ->and($html)->toContain('if (startedFilling ||')
            ->and($html)->toContain('startedFilling = true;');
    });

    /**
     * Tracking is never a precondition for buying. A visitor whose scripts
     * never ran raises nothing, reports nothing, and still places an Order —
     * which is the whole reason the form is a real form with a real action.
     */
    it('takes an order from a visitor who reported nothing at all', function (ProviderScript $script) {
        $page = startingPage($script, $product = Product::factory()->create());

        $this->post(route('checkout.store'), capturePayload($page))->assertRedirect();

        expect(Order::count())->toBe(1)
            ->and(TrackingEvent::where('moment', TrackingMoment::CheckoutStarted)->count())->toBe(0)
            ->and($product->exists)->toBeTrue();
    })->with('tracking providers');
});

describe('the beacon', function () {
    it('writes the server copy of everything the moment reports', function (ProviderScript $script) {
        $page = startingPage($script, $jacket = Product::factory()->create(['price' => 99000]));

        reportStart($page, $jacket->getKey().':2')->assertNoContent();

        expect(TrackingEvent::query()->orderBy('id')->pluck('event')->all())->toBe($script->checkoutStartedEvents());

        $row = TrackingEvent::query()->firstOrFail();

        expect($row->moment)->toBe(TrackingMoment::CheckoutStarted)
            ->and($row->deduplication_id)->toBe('load-3f9a2c')
            ->and($row->page_slug)->toBe('promo')
            ->and($row->order_id)->toBeNull()
            ->and($row->source_url)->toBe(route('landing.show', 'promo'));
    })->with('tracking providers');

    /**
     * What the Provider is actually told, read back off the request the sweep
     * made: the money and the basket the visitor had in front of them, and no
     * Order reference, because there is no Order.
     */
    it('reports what the visitor had ticked, at the page\'s own prices', function (ProviderScript $script) {
        $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
        $scarf = Product::factory()->create(['name' => 'Scarf', 'price' => 15000]);

        $page = startingPage($script, [$jacket, $scarf], ['product_picker' => true]);

        reportStart($page, $jacket->getKey().':2')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $sent = $script->sentEvents()[0];

        expect($script->valueOf($sent))->toBe(1980.0)
            ->and($script->contentsOf($sent))->toBe([
                ['id' => (string) $jacket->getKey(), 'quantity' => 2, 'price' => 990.0],
            ])
            ->and($script->orderReferenceOf($sent))->toBeNull()
            ->and($script->sourceUrlOf($sent))->toBe(route('landing.show', 'promo'));
    })->with('tracking providers');

    /**
     * The prices are the Page's and nothing that arrives may say otherwise —
     * the same rule a Delivery Charge is derived under at Capture. A Product
     * this Page does not sell adds no line and no money however loudly it is
     * named, and a quantity nobody could order is brought back inside what a
     * Checkout would take.
     */
    it('prices what it is told about from the page, and sells nothing the page does not', function (ProviderScript $script) {
        $jacket = Product::factory()->create(['price' => 99000]);
        $elsewhere = Product::factory()->create(['price' => 500000]);

        $page = startingPage($script, $jacket);

        reportStart($page, $jacket->getKey().':1,'.$elsewhere->getKey().':3')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $sent = $script->sentEvents()[0];

        expect($script->valueOf($sent))->toBe(990.0)
            ->and($script->contentsOf($sent))->toBe([
                ['id' => (string) $jacket->getKey(), 'quantity' => 1, 'price' => 990.0],
            ]);
    })->with('tracking providers');

    it('brings a quantity nobody could order back inside what a checkout would take', function (ProviderScript $script) {
        $jacket = Product::factory()->create(['price' => 99000]);

        reportStart(startingPage($script, $jacket), $jacket->getKey().':9999')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $contents = $script->contentsOf($script->sentEvents()[0]);

        expect($contents[0]['quantity'])->toBe(config('giopages.checkout.max_quantity'));
    })->with('tracking providers');

    /**
     * A visitor who has chosen nothing yet is looking at what the form opens
     * on, which is the Page's Default Selection where it asks them to choose
     * and everything it sells where it does not. That is the figure the first
     * paint shows them, so it is the one reported for them.
     */
    it('reports what the page\'s checkout opens on where the browser named nothing', function (ProviderScript $script, array $checkout, float $value) {
        $jacket = Product::factory()->create(['price' => 99000]);
        $scarf = Product::factory()->create(['price' => 15000]);

        $page = startingPage($script, [$jacket, $scarf], [
            ...$checkout,
            'default_selection' => array_key_exists('product_picker', $checkout) ? [$scarf->getKey()] : [],
        ]);

        reportStart($page)->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        expect($script->valueOf($script->sentEvents()[0]))->toBe($value);
    })->with('tracking providers')->with([
        'a checkout that sells everything it lists' => [[], 1140.0],
        'a checkout whose picker opens on one of them' => [['product_picker' => true], 150.0],
    ]);

    it('counts one checkout started however many times the browser reports it', function (ProviderScript $script) {
        $page = startingPage($script, $jacket = Product::factory()->create(['price' => 99000]));

        reportStart($page, $jacket->getKey().':1')->assertNoContent();
        reportStart($page, $jacket->getKey().':1')->assertNoContent();

        /** A second page load is a second Moment, under its own identifier. */
        reportStart($page, $jacket->getKey().':1', identifier: 'load-second')->assertNoContent();

        expect(TrackingEvent::count())->toBe(count($script->checkoutStartedEvents()) * 2);
    })->with('tracking providers');

    it('writes nothing where the event is switched off', function (ProviderScript $script, Closure $switchOff) {
        $page = startingPage($script, $jacket = Product::factory()->create());

        $switchOff($page, $script);

        reportStart($page, $jacket->getKey().':1')->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with('tracking providers')->with([
        'by this page' => [function (Page $page, ProviderScript $script): void {
            $page->update(['tracking_config' => [
                'events' => [$script->provider()->value => array_fill_keys($script->checkoutStartedEvents(), false)],
            ]]);
        }],
        'by the installation' => [function (Page $page, ProviderScript $script): void {
            app(TrackingSettings::class)->with([
                'eventDefaults' => [$script->provider()->value => array_fill_keys($script->checkoutStartedEvents(), false)],
            ])->save();
        }],
    ]);
});
