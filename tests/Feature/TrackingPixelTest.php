<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Support\Orders\OrderOwnership;
use App\Support\Settings\TrackingSettings;
use App\Support\Tracking\TrackingSnippet;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Payments\SslCommerzScript;
use Tests\Fixtures\Tracking\FacebookScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Seam B: what the browser reports, beside what the server already did.
 *
 * The same Order is reported twice — once from here and once from the outbox
 * — and the Provider counts it once, because both copies carry the order
 * number as the deduplication id. Everything is read out of the served
 * document through the Provider's own script, so a second platform is a
 * subclass and not a second test file.
 */

/**
 * A Cash on Delivery Order captured for one Product, with the Provider
 * switched on before the Capture so the server copy is written
 * too.
 */
function orderPlacedOn(ProviderScript $script, ?Product $product = null): Order
{
    $script->switchOn();

    $order = orderAsCaptured(lines: [['product' => $product ?? Product::factory()->create(), 'kilograms' => 1]]);
    recordCaptureOf($order);

    return $order;
}

/**
 * The Order Complete Page as the browser that placed the Order receives it.
 *
 * The cookie is written by hand rather than replayed off the Capture
 * response, because what unlocks the Purchase is what the browser sends, and
 * that is the list of order numbers Capture handed it.
 */
function orderCompletePageFor(Order $order, bool $owning = true): string
{
    $request = $owning
        ? test()->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
        : test();

    return $request->get(route('checkout.order-complete', $order->order_number))->assertOk()->getContent();
}

it('reports the page load and the purchase from the order complete page of the browser that placed it', function (ProviderScript $script) {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);

    $order = orderPlacedOn($script, $product);

    $html = orderCompletePageFor($order);

    expect($script->carriesBaseCode($html))->toBeTrue();

    $events = $script->browserEventsIn($html);

    expect(array_map($script->eventNameOf(...), $events))->toBe([$script->pageViewEvent(), $script->purchaseEvent()]);

    /** The Purchase carries what the server copy carries: the same money, the same basket, the same reference. */
    $purchase = $events[1];

    /** The Order's total, the 1–5 kg band's Delivery Charge on top of the line. */
    expect($script->valueOf($purchase))->toBe(1110.0)
        ->and($script->currencyOf($purchase))->toBe('BDT')
        ->and($script->orderReferenceOf($purchase))->toBe($order->order_number)
        ->and($script->contentsOf($purchase))->toBe([
            ['id' => (string) $product->getKey(), 'quantity' => 1, 'price' => 990.0],
        ]);
})->with('tracking providers');

/**
 * The address is a bearer handle that ends up in history, in a Referer and in
 * a gateway's logs, and Meta's deduplication window is only 48 hours — so a
 * Pixel that fired on every load would report a second Purchase to anyone
 * holding the link, and a third the next week to the Customer themselves.
 */
it('reports no purchase to a browser that did not place the order, whoever is holding the link', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    $html = orderCompletePageFor($order, owning: false);

    expect($script->carriesBaseCode($html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent()]);
})->with('tracking providers');

it('reports the page load and nothing else from the retry page, where nothing has been bought yet', function (ProviderScript $script) {
    $script->switchOn();

    /** An Order that chose an online method and has not been verified: Unpaid, and drawn by the Retry Page. */
    $order = capturedFor(new SslCommerzScript)->order;

    $html = $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
        ->get(route('checkout.retry', $order->order_number))
        ->assertOk()
        ->getContent();

    expect($script->carriesBaseCode($html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent()]);
})->with('tracking providers');

/**
 * The Processing Page is the one address a gateway's own logs hold, and it is
 * transient — drawn for a second or two before the Verification moves the
 * Customer on. It carries no Pixel at all.
 */
it('carries no pixel on the processing page', function (ProviderScript $script) {
    $script->switchOn();

    $payment = capturedFor(new SslCommerzScript);

    $html = $this->get(processingUrl($payment))->assertOk()->getContent();

    expect($script->carriesBaseCode($html))->toBeFalse()
        ->and($script->browserEventsIn($html))->toBe([])
        ->and($html)->not->toContain(TrackingSnippet::CONSENT_HOOK);
})->with('tracking providers');

it('hands the browser copy and the server copy the same order number, so the provider counts one purchase', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    $browser = $script->browserEventsIn(orderCompletePageFor($order))[1];

    expect($script->deduplicationIdOf($browser))->toBe($order->order_number)
        ->and(TrackingEvent::sole()->deduplication_id)->toBe($order->order_number)
        ->and($script->eventNameOf($browser))->toBe(TrackingEvent::sole()->event);
})->with('tracking providers');

/**
 * The consent hook is one global, consulted. Absent or true means fire: a
 * hook defaulting to blocking would silently disable tracking for every
 * Merchant who never heard of it, which is most of them.
 */
it('consults one global for consent and fires unless it is explicitly false', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    expect(orderCompletePageFor($order))->toContain('if (window.'.TrackingSnippet::CONSENT_HOOK.' === false) {');
})->with('tracking providers');

it('carries no pixel and requests nothing from anyone where no provider is switched on', function () {
    $html = orderCompletePageFor(orderAsCaptured());

    /** The document a store reporting nowhere serves is the one it has always served. */
    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain(TrackingSnippet::CONSENT_HOOK)
        ->and($html)->not->toContain('src=');
});

it('carries no pixel for a provider the merchant has switched off, because that is what a campaign break is', function (ProviderScript $script) {
    $order = orderAsCaptured();

    $script->switchOn();
    $script->switchOff();

    expect(orderCompletePageFor($order))->not->toContain('<script');
})->with('tracking providers');

/**
 * The access token is the server half's credential. The browser addresses a
 * Provider by its public identifier alone, so a Merchant who has pasted
 * the dataset and not yet the token is already reporting from the browser.
 */
it('still carries the pixel for a provider that has no access token yet', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    $script->withoutAccessToken();

    expect($script->carriesBaseCode(orderCompletePageFor($order)))->toBeTrue();
})->with('tracking providers');

/**
 * A visitor on any of these three pages has already bought, or is about to be
 * moved on by a Verification. None of them is a Visit: counting them would
 * dilute the Merchant's own Conversion Rate with the people who converted.
 */
it('counts the order complete page as no visit, however often it is reloaded', function () {
    $order = orderPlacedOn(new FacebookScript);

    orderCompletePageFor($order);
    orderCompletePageFor($order);

    /** The one row the Capture wrote, and nothing the page loads added. */
    expect(TrackingEvent::count())->toBe(1);
});

it('counts the processing page and the retry page as no visits', function () {
    (new FacebookScript)->switchOn();

    $payment = capturedFor(new SslCommerzScript);

    $this->get(processingUrl($payment))->assertOk();
    $this->get(route('checkout.retry', $payment->order->order_number))->assertOk();

    expect(TrackingEvent::count())->toBe(0);
});

/**
 * A storefront page as a browser's first visit receives it: the whole root
 * document, not the Inertia answer a later visit gets.
 */
function storefrontDocument(?string $address = null): string
{
    $address ??= route('product', Product::factory()->create()->slug);

    return test()->get($address)->assertOk()->getContent();
}

/**
 * Every storefront page is one Inertia root document, so that is where the
 * Pixel goes: the base code once, and one function per Moment the storefront
 * reports from the browser, which it calls as each one happens — a load on
 * every visit, a Product's own page, a Product going from none to some in the
 * Cart, and the Checkout opened with something in it.
 */
describe('the storefront', function () {
    it('carries the pixel and the moments the browser reports on every storefront page', function (ProviderScript $script, string $address) {
        $script->switchOn();

        $html = storefrontDocument($address);

        expect($script->carriesBaseCode($html))->toBeTrue()
            ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([
                ...$script->visitEvents(),
                ...$script->productViewedEvents(),
                ...$script->addedToCartEvents(),
                ...$script->checkoutStartedEvents(),
            ]);
    })->with('tracking providers')->with([
        'a product page' => fn (): string => route('product', Product::factory()->create()->slug),
        'the checkout' => fn (): string => route('checkout'),
    ]);

    /**
     * One document is served to everyone, so the deduplication id and what
     * was being bought are both the browser's to supply when the Moment
     * happens — and the load is reported once, with its id, rather than by a
     * bare base code no beacon can deduplicate against.
     */
    it('leaves the deduplication id and the basket to the browser', function (ProviderScript $script) {
        $script->switchOn();

        $events = $script->browserEventsIn(storefrontDocument());
        $load = array_shift($events);

        expect($script->deduplicationIdOf($load))->not->toBeNull()
            ->and($script->reportsLiveSelection($load))->toBeFalse();

        foreach ($events as $event) {
            expect($script->deduplicationIdOf($event))->toBe($script->deduplicationIdOf($load))
                ->and($script->reportsLiveSelection($event))->toBeTrue()
                ->and($script->namesWhatWasBought($event))->toBeTrue();
        }
    })->with('tracking providers');

    it('leaves out an event the store has switched off', function (ProviderScript $script) {
        $script->switchOn();

        app(TrackingSettings::class)->with([
            'eventDefaults' => [$script->provider()->value => array_fill_keys($script->addedToCartEvents(), false)],
        ])->save();

        $names = array_map($script->eventNameOf(...), $script->browserEventsIn(storefrontDocument()));

        expect($names)->not->toContain(...$script->addedToCartEvents())
            ->and($names)->toContain(...$script->productViewedEvents());
    })->with('tracking providers');

    it('names no products from the browser where the store does not name them', function (ProviderScript $script) {
        $script->switchOn();

        app(TrackingSettings::class)->with(['namesProducts' => false])->save();

        $aboutProducts = array_slice($script->browserEventsIn(storefrontDocument()), 1);

        foreach ($aboutProducts as $event) {
            expect($script->namesWhatWasBought($event))->toBeFalse();
        }
    })->with('tracking providers');

    it('carries no pixel where the store reports nowhere', function (ProviderScript $script) {
        $script->switchOn();
        $script->switchOff();

        $html = storefrontDocument();

        expect($html)->not->toContain(TrackingSnippet::CONSENT_HOOK)
            ->and($html)->not->toContain('window.'.TrackingSnippet::MOMENTS)
            ->and($script->browserEventsIn($html))->toBe([]);
    })->with('tracking providers');

    /**
     * The beacon costs a request on every load, so the storefront is told
     * where it is only where something would be written: a Provider to
     * report to, or the Merchant's own Visit count.
     */
    it('tells the storefront where the beacon is only where it would write something', function (bool $reports, bool $counts, bool $told) {
        if ($reports) {
            (new FacebookScript)->switchOn();
        }

        app(TrackingSettings::class)->with(['countsVisits' => $counts])->save();

        test()->get(route('checkout'))->assertInertia(fn (Assert $page) => $page
            ->where('tracking.beacon', $told ? route('tracking.report') : null)
            ->where('tracking.moments', TrackingSnippet::MOMENTS));
    })->with([
        'reporting somewhere' => [true, false, true],
        'counting visits' => [false, true, true],
        'neither' => [false, false, false],
    ]);
});
