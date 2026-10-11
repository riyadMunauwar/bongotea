<?php

use App\Enums\TrackingEventState;
use App\Enums\TrackingMoment;
use App\Models\Order;
use App\Models\TrackingContext;
use App\Models\TrackingEvent;
use App\Support\Tracking\MomentRecorder;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Payments\SslCommerzScript;
use Tests\Fixtures\Tracking\FacebookScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Seam A: what the Capture Moment writes down.
 *
 * Every Order here is captured by `OrderCapture` and its Moment recorded with
 * a real request's context, and everything is asserted on the outbox row that
 * lands, because that is the whole of what a Moment does — no ad platform is
 * spoken to from a request, and the row is the only evidence there is until
 * the sweep runs.
 */
it('writes one pending purchase row per switched-on provider when a cash on delivery order is captured', function (ProviderScript $script) {
    $script->switchOn();

    Http::fake();

    $order = orderAsCaptured();
    recordCaptureOf($order);

    /** Nothing left the server during the Capture: the outbox is the whole of what happened. */
    Http::assertNothingSent();

    $row = TrackingEvent::sole();

    expect($row->provider)->toBe($script->provider())
        ->and($row->moment)->toBe(TrackingMoment::Capture)
        ->and($row->event)->toBe($script->purchaseEvent())
        ->and($row->state)->toBe(TrackingEventState::Pending)
        ->and($row->attempts)->toBe(0)
        ->and($row->sent_at)->toBeNull()

        /** The order number, so the browser copy of the same Purchase deduplicates against it. */
        ->and($row->deduplication_id)->toBe($order->order_number)

        ->and($row->order_id)->toBe($order->getKey())

        /** The address a Provider is told about is the one the visitor ordered from. */
        ->and($row->source_url)->toBe('https://shop.test/checkout')

        ->and($row->occurred_at->timestamp)->toBe($order->created_at->timestamp);
})->with('tracking providers');

it('records what the browser told us, unhashed, because a provider matches on it itself', function (ProviderScript $script) {
    $script->switchOn();

    recordCaptureOf(orderAsCaptured(), server: [
        'REMOTE_ADDR' => '203.0.113.42',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile',
    ]);

    $row = TrackingEvent::sole();

    expect($row->ip_address)->toBe('203.0.113.42')
        ->and($row->user_agent)->toBe('Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile');
})->with('tracking providers');

it('records the click identifiers the browser sent, unhashed, because a provider matches on them itself', function (ProviderScript $script) {
    $script->switchOn();

    recordCaptureOf(orderAsCaptured(), [TrackingContext::CLICK_IDS_FIELD => $script->browserClickIds()]);

    expect(TrackingEvent::sole()->click_ids)->toBe($script->browserClickIds());
})->with('tracking providers');

it('reports no click identifiers for a visitor whose browser sent none', function (ProviderScript $script) {
    $script->switchOn();

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::sole()->click_ids)->toBeNull();
})->with('tracking providers');

/**
 * These arrive in a field no Customer can see or correct, from a script their
 * browser may not have run. Filtered rather than checked: an Order refused
 * over one would be a sale lost to tracking.
 */
it('keeps only the click identifiers a provider recognises, cut to length', function (ProviderScript $script) {
    $script->switchOn();

    recordCaptureOf(orderAsCaptured(), [
        TrackingContext::CLICK_IDS_FIELD => [
            $script->clickIdNames()[0] => str_repeat('x', 400),
            'ttclid' => 'a name no provider here knows',
            'nested' => ['not even a string'],
        ],
    ]);

    expect(TrackingEvent::sole()->click_ids)->toBe([$script->clickIdNames()[0] => str_repeat('x', 255)]);
})->with('tracking providers');

it('holds no personal detail on the row: what a provider is told about the customer is read from the order at delivery', function (ProviderScript $script) {
    $script->switchOn();

    recordCaptureOf(orderAsCaptured());

    $row = TrackingEvent::sole()->getAttributes();

    expect(json_encode($row))
        ->not->toContain('Rahim Uddin')
        ->not->toContain('01712345678')
        ->not->toContain('+8801712345678')
        ->not->toContain('House 4, Road 12, Banani');
})->with('tracking providers');

it('reports nothing at capture for an order that chose an online method, because the money has not moved', function (ProviderScript $script) {
    $script->switchOn();

    $payment = capturedFor(new SslCommerzScript);
    recordCaptureOf($payment->order);

    expect(TrackingEvent::count())->toBe(0)
        ->and(Order::sole()->payment_method->isOnline())->toBeTrue();
})->with('tracking providers');

it('reports nothing at all when no provider is switched on', function () {
    Http::fake();

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::count())->toBe(0);

    Http::assertNothingSent();
});

it('reports nothing to a provider the merchant has switched off, so a campaign break accumulates nothing', function (ProviderScript $script) {
    $script->switchOn();
    $script->switchOff();

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::count())->toBe(0);
})->with('tracking providers');

it('still accumulates rows for a provider that is on but has no access token yet, so fixing the token loses nothing', function (ProviderScript $script) {
    $script->switchOn();
    $script->withoutAccessToken();

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Pending);
})->with('tracking providers');

/**
 * The invariant the outbox's unique index carries, rather than a check in
 * three call sites: a Verification is reached from the Processing Page, a
 * Notification and the sweep, and only the first of them may write.
 */
it('writes one row however many times the same moment is recorded', function () {
    (new FacebookScript)->switchOn();

    $order = orderAsCaptured();
    recordCaptureOf($order);

    app(MomentRecorder::class)->captured($order, TrackingContext::none());
    app(MomentRecorder::class)->captured($order, TrackingContext::none());

    expect(TrackingEvent::count())->toBe(1)

        /** And what it holds is what the first recording wrote, not what the later calls would have. */
        ->and(TrackingEvent::sole()->source_url)->toBe('https://shop.test/checkout');
});
