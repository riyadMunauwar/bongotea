<?php

use App\Models\Destination;
use App\Models\Order;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Support\Orders\OrderOwnership;
use App\Support\Tracking\TrackingSnippet;
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
 * A Cash on Delivery Order captured on a Page selling one Product, with the
 * Provider's Destination configured before the Capture so the server copy is
 * written too.
 */
function orderPlacedOn(ProviderScript $script, ?Product $product = null): Order
{
    $script->makeDestination();

    test()->postJson(route('checkout.store'), capturePayload(sellingPage($product ?? Product::factory()->create())))
        ->assertCreated();

    return Order::sole();
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

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue();

    $events = $script->browserEventsIn($html);

    expect(array_map($script->eventNameOf(...), $events))->toBe([$script->pageViewEvent(), $script->purchaseEvent()]);

    /** The Purchase carries what the server copy carries: the same money, the same basket, the same reference. */
    $purchase = $events[1];

    expect($script->valueOf($purchase))->toBe(1050.0)
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

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent()]);
})->with('tracking providers');

it('reports the page load and nothing else from the retry page, where nothing has been bought yet', function (ProviderScript $script) {
    $script->makeDestination();

    /** An Order that chose an online method and has not been verified: Unpaid, and drawn by the Retry Page. */
    $order = capturedFor(new SslCommerzScript)->order;

    $html = $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
        ->get(route('checkout.retry', $order->order_number))
        ->assertOk()
        ->getContent();

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent()]);
})->with('tracking providers');

/**
 * The Processing Page is the one address a gateway's own logs hold, and it is
 * transient — drawn for a second or two before the Verification moves the
 * Customer on. It carries no Pixel at all.
 */
it('carries no pixel on the processing page', function (ProviderScript $script) {
    $script->makeDestination();

    $payment = capturedFor(new SslCommerzScript);

    $html = $this->get(processingUrl($payment))->assertOk()->getContent();

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeFalse()
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

it('carries no pixel and requests nothing from anyone where no destination is configured', function () {
    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    $html = orderCompletePageFor(Order::sole());

    /** The document a Page with no Destination serves is the one it has always served. */
    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain(TrackingSnippet::CONSENT_HOOK)
        ->and($html)->not->toContain('src=');
});

it('carries no pixel for a destination the merchant has switched off, because that is what a campaign break is', function (ProviderScript $script) {
    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    $destination = $script->makeDestination();
    $destination->update(['enabled' => false]);

    expect(orderCompletePageFor(Order::sole()))->not->toContain('<script');
})->with('tracking providers');

/**
 * The access token is the server half's credential. The browser addresses a
 * Destination by its public identifier alone, so a Merchant who has pasted
 * the dataset and not yet the token is already reporting from the browser.
 */
it('still carries the pixel for a destination that has no access token yet', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    Destination::sole()->update(['access_token' => null]);

    expect($script->carriesBaseCodeFor(Destination::sole(), orderCompletePageFor($order)))->toBeTrue();
})->with('tracking providers');

it('reports to the destination of an order whose page is in the bin', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    $order->page->delete();

    $html = orderCompletePageFor($order);

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent(), $script->purchaseEvent()]);
})->with('tracking providers');

it('falls back to the default destination for an order whose page is gone for good', function (ProviderScript $script) {
    $order = orderPlacedOn($script);

    $order->page->forceDelete();

    $html = orderCompletePageFor($order->refresh());

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent(), $script->purchaseEvent()]);
})->with('tracking providers');

/**
 * A visitor on any of these three pages has already bought, or is about to be
 * moved on by a Verification. None of them is a Visit: counting them would
 * dilute the Merchant's own Conversion Rate with the people who converted.
 */
it('counts the order complete page as no visit, however often it is reloaded', function () {
    (new FacebookScript)->makeDestination();

    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    $order = Order::sole();

    orderCompletePageFor($order);
    orderCompletePageFor($order);

    /** The one row the Capture wrote, and nothing the page loads added. */
    expect(TrackingEvent::count())->toBe(1);
});

it('counts the processing page and the retry page as no visits', function () {
    (new FacebookScript)->makeDestination();

    $payment = capturedFor(new SslCommerzScript);

    $this->get(processingUrl($payment))->assertOk();
    $this->get(route('checkout.retry', $payment->order->order_number))->assertOk();

    expect(TrackingEvent::count())->toBe(0);
});
