<?php

use App\Enums\TrackingEventState;
use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Order;
use App\Models\Product;
use App\Models\TrackingContext;
use App\Models\TrackingEvent;
use App\Support\Orders\OrderOwnership;
use App\Support\Tracking\MomentRecorder;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Payments\SslCommerzScript;
use Tests\Fixtures\Tracking\FacebookScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Seam A: what a Moment writes down.
 *
 * Everything here is driven through the real Checkout POST and asserted on
 * the outbox row that lands, because that is the whole of what a Moment does
 * — no ad platform is spoken to from a request, and the row is the only
 * evidence there is until the sweep runs.
 */
it('writes one pending purchase row per applicable destination when a cash on delivery order is captured', function (ProviderScript $script) {
    $destination = $script->makeDestination();
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $page = sellingPage($product);

    Http::fake();

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    /** Nothing left the server during the Capture: the outbox is the whole of what happened. */
    Http::assertNothingSent();

    $order = Order::sole();
    $row = TrackingEvent::sole();

    expect($row->destination_id)->toBe($destination->getKey())
        ->and($row->moment)->toBe(TrackingMoment::Capture)
        ->and($row->event)->toBe($script->purchaseEvent())
        ->and($row->state)->toBe(TrackingEventState::Pending)
        ->and($row->attempts)->toBe(0)
        ->and($row->sent_at)->toBeNull()

        /** The order number, so the browser copy of the same Purchase deduplicates against it. */
        ->and($row->deduplication_id)->toBe($order->order_number)

        ->and($row->order_id)->toBe($order->getKey())
        ->and($row->page_id)->toBe($page->getKey())
        ->and($row->page_slug)->toBe('promo')

        /** The address a Provider is told about is the Page the visitor was on, not the Checkout endpoint. */
        ->and($row->source_url)->toBe(route('landing.show', 'promo'))

        ->and($row->occurred_at->timestamp)->toBe($order->created_at->timestamp);
})->with('tracking providers');

it('records what the browser told us, unhashed, because a provider matches on it itself', function (ProviderScript $script) {
    $script->makeDestination();
    $page = sellingPage(Product::factory()->create());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile'])
        ->postJson(route('checkout.store'), capturePayload($page))
        ->assertCreated();

    $row = TrackingEvent::sole();

    expect($row->ip_address)->toBe('203.0.113.42')
        ->and($row->user_agent)->toBe('Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile');
})->with('tracking providers');

/**
 * The click identifiers are the Checkout island's half: it reads the
 * platform's cookies and the click id this page was opened with at submit
 * time and writes them into hidden inputs, because the markup itself was
 * written to disk days ago for every visitor alike.
 */
it('records the click identifiers the island read from the browser, unhashed, because a provider matches on them itself', function (ProviderScript $script) {
    $script->makeDestination();
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page, [
        TrackingContext::CLICK_IDS_FIELD => $script->browserClickIds(),
    ]))->assertCreated();

    expect(TrackingEvent::sole()->click_ids)->toBe($script->browserClickIds());
})->with('tracking providers');

/**
 * Tracking is never a precondition for buying. A visitor whose scripts never
 * ran posts the form as it stands, which carries none of this, and their
 * Order is captured exactly as it always was.
 */
it('captures an order from a visitor whose browser ran no script, reporting no click identifiers', function (ProviderScript $script) {
    $script->makeDestination();
    $page = sellingPage(Product::factory()->create());

    $this->post(route('checkout.store'), capturePayload($page))->assertRedirect();

    expect(Order::count())->toBe(1)
        ->and(TrackingEvent::sole()->click_ids)->toBeNull();
})->with('tracking providers');

/**
 * These arrive in a field no Customer can see or correct, from a script their
 * browser may not have run. Filtered rather than checked: an Order refused
 * over one would be a sale lost to tracking.
 */
it('never refuses an order over a click identifier, and keeps only what a provider recognises', function (ProviderScript $script) {
    $script->makeDestination();
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page, [
        TrackingContext::CLICK_IDS_FIELD => [
            $script->clickIdNames()[0] => str_repeat('x', 400),
            'ttclid' => 'a name no provider here knows',
            'nested' => ['not even a string'],
        ],
    ]))->assertCreated();

    expect(Order::count())->toBe(1)
        ->and(TrackingEvent::sole()->click_ids)->toBe([$script->clickIdNames()[0] => str_repeat('x', 255)]);
})->with('tracking providers');

/**
 * The bytes a visitor is served are written once and handed to everyone. A
 * cookie or a click id read at render time would be frozen into them and sent
 * to every visitor after the first — which is why the island reads both in
 * the browser instead, and why the document carries no box for them.
 */
it('bakes nothing about the visitor into the checkout, whatever cookie or click id the page is fetched with', function () {
    Destination::factory()->create();
    sellingPage(Product::factory()->create());

    $plain = $this->get('/promo');

    /** After the plain fetch: a cookie given to the test client rides on every request it makes afterwards. */
    $clicked = $this->withCookie('_fbc', 'fb.1.1757000000000.IwAR0abcdefghijklmnop')
        ->withCookie('_fbp', 'fb.1.1757000000000.1098765432')
        ->get('/promo?fbclid=IwAR0abcdefghijklmnop');

    expect($clicked->headers->get('X-GioPages-Cache'))->toBe('miss')
        ->and($clicked->getContent())->toBe($plain->getContent())
        ->and($plain->getContent())->not->toContain('IwAR0abcdefghijklmnop')

        /** And no box drawn empty for them, which a visitor running no script would post blank. */
        ->and($plain->getContent())->not->toContain('name="click_ids')

        /** Read in the browser instead, at the moment the form is submitted. */
        ->and($plain->getContent())->toContain("cookie('_fbc')")
        ->and($plain->getContent())->toContain('window.location.search.match(/[?&]fbclid=');
});

it('holds no personal detail on the row: what a provider is told about the customer is read from the order at delivery', function (ProviderScript $script) {
    $script->makeDestination();
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $row = TrackingEvent::sole()->getAttributes();

    expect(json_encode($row))
        ->not->toContain('Rahim Uddin')
        ->not->toContain('01712345678')
        ->not->toContain('+8801712345678')
        ->not->toContain('House 4, Road 12, Banani');
})->with('tracking providers');

it('reports nothing at capture for an order that chose an online method, because the money has not moved', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    capturedFor($gateway);

    expect(TrackingEvent::count())->toBe(0)
        ->and(Order::sole()->payment_method->isOnline())->toBeTrue();
})->with('tracking providers');

it('reports nothing at all when no destination is configured', function () {
    $page = sellingPage(Product::factory()->create());

    Http::fake();

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(TrackingEvent::count())->toBe(0);

    Http::assertNothingSent();
});

it('reports nothing to a destination the merchant has switched off, so a campaign break accumulates nothing', function () {
    Destination::factory()->disabled()->create();

    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    expect(TrackingEvent::count())->toBe(0);
});

it('still accumulates rows for a destination that is on but has no credentials yet, so fixing the token loses nothing', function () {
    Destination::factory()->withoutCredentials()->create();

    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Pending);
});

it('reports only to the provider default, not to every destination the merchant holds', function () {
    $default = Destination::factory()->create(['label' => 'Brand Ads']);
    $other = Destination::factory()->notDefault()->create(['label' => "A client's account"]);

    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    expect(TrackingEvent::sole()->destination_id)->toBe($default->getKey())
        ->and($other->trackingEvents()->count())->toBe(0);
});

/**
 * Tracking is never a precondition for buying. The Order is committed before
 * the Moment is recorded, so anything that throws here would show a 500 for
 * an Order that exists, withhold the ownership cookie, and invite the
 * Customer to submit again.
 */
it('captures the order and hands over ownership even when recording the moment fails', function () {
    Destination::factory()->create();

    $this->mock(MomentRecorder::class)
        ->shouldReceive('captured')
        ->once()
        ->andThrow(new RuntimeException('the outbox is on fire'));

    $response = $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())));

    $response->assertCreated();

    $order = Order::sole();

    expect($response->json('redirect'))->toBe(route('checkout.order-complete', $order->order_number))
        ->and($response->getCookie(OrderOwnership::COOKIE))->not->toBeNull()
        ->and(TrackingEvent::count())->toBe(0);
});

/**
 * The invariant the outbox's unique index carries, rather than a check in
 * three call sites: a Verification is reached from the Processing Page, a
 * Notification and the sweep, and only the first of them may write.
 */
it('writes one row however many times the same moment is recorded', function () {
    $script = new FacebookScript;
    $script->makeDestination();

    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    $order = Order::sole();

    app(MomentRecorder::class)->captured($order, TrackingContext::none());
    app(MomentRecorder::class)->captured($order, TrackingContext::none());

    expect(TrackingEvent::count())->toBe(1)

        /** And what it holds is what the Capture wrote, not what the later calls would have. */
        ->and(TrackingEvent::sole()->source_url)->toBe(route('landing.show', 'promo'));
});
