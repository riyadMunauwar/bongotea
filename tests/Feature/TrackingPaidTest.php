<?php

use App\Enums\PaymentState;
use App\Enums\TrackingEventState;
use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TrackingContext;
use App\Models\TrackingEvent;
use App\Support\Payments\PaymentSweep;
use App\Support\Payments\PaymentVerifier;
use App\Support\Tracking\MomentRecorder;
use Tests\Fixtures\Payments\SslCommerzScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Run the payment sweep at an age the Payment has reached, which is the one
 * thing the sweep decides anything by.
 */
function sweepingPaymentsAfter(Payment $payment, int $seconds): void
{
    test()->travelTo($payment->created_at->addSeconds($seconds + 1));

    test()->artisan('payments:sweep')->assertSuccessful();
}

/**
 * Seam A, the other half: the Purchase an Order paid online reports, which
 * belongs to the Verification that says Paid rather than to the Capture.
 *
 * Driven through a real Capture, the real verification endpoints and the
 * real sweep, and asserted on the outbox row that lands — no ad platform
 * is spoken to from any of them, and the row is the whole of what a Moment
 * does. The gateway is one script, because none of this is about which
 * gateway took the money.
 */
it('reports nothing at capture and one purchase once a verification marks the payment paid', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);
    recordCaptureOf($payment->order);

    /** Money has not moved yet: the Order may still be abandoned at the gateway. */
    expect(TrackingEvent::count())->toBe(0);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $gateway->fakeVerifiedPaid($payment);

    $this->postJson(verifyUrl($payment))->assertOk();

    $order = Order::sole();
    $row = TrackingEvent::sole();

    expect($row->moment)->toBe(TrackingMoment::Paid)
        ->and($row->event)->toBe($script->purchaseEvent())
        ->and($row->state)->toBe(TrackingEventState::Pending)
        ->and($row->attempts)->toBe(0)
        ->and($row->sent_at)->toBeNull()

        /** The order number, the same string a Capture Moment writes, so the browser's copy deduplicates against either. */
        ->and($row->deduplication_id)->toBe($order->order_number)

        ->and($row->order_id)->toBe($order->getKey())
        /** When the money was found to have moved, not when the row is swept out. */
        ->and($row->occurred_at->timestamp)->toBe($payment->refresh()->verified_at->timestamp);
})->with('tracking providers');

/**
 * The three places a Verification runs from all say the same thing, and each
 * of them writes. There is nothing to coordinate between them because there
 * is nothing they could disagree about.
 */
it('reports the purchase whichever of the three reaches the verification', function (ProviderScript $script, string $reachedFrom) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedPaid($payment);

    match ($reachedFrom) {
        'the processing page' => $this->postJson(verifyUrl($payment))->assertOk(),
        'a gateway notification' => $this->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk(),
        'the sweep' => sweepingPaymentsAfter($payment, PaymentSweep::RECHECK_AFTER_SECONDS),
    };

    expect($payment->refresh()->state)->toBe(PaymentState::Paid);

    $row = TrackingEvent::sole();

    expect($row->moment)->toBe(TrackingMoment::Paid)
        ->and($row->event)->toBe($script->purchaseEvent())
        ->and($row->deduplication_id)->toBe(Order::sole()->order_number);
})->with('tracking providers')->with([
    'the processing page',
    'a gateway notification',
    'the sweep',
]);

/**
 * The invariant the outbox's unique index carries rather than a check in
 * three call sites. Three stale copies of one Pending Payment is what the
 * Processing Page, a Notification and the sweep landing together actually
 * look like: each asks the gateway, the first writes Paid, and all three go
 * on to report it.
 */
it('writes one purchase when all three verify at once, because the second and third writes are no-ops', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedPaid($payment);

    $verifier = app(PaymentVerifier::class);

    foreach ([Payment::sole(), Payment::sole(), Payment::sole()] as $stale) {
        $verifier->verify($stale);
    }

    expect(TrackingEvent::count())->toBe(1)
        ->and(TrackingEvent::sole()->moment)->toBe(TrackingMoment::Paid);
})->with('tracking providers');

it('reports no purchase for an online order the gateway never confirms', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedInvalid($payment);

    $this->postJson(verifyUrl($payment))->assertOk();

    expect($payment->refresh()->state)->toBe(PaymentState::Failed)
        ->and(TrackingEvent::count())->toBe(0);
})->with('tracking providers');

it('reports no purchase for an online order abandoned at the gateway and left to expire', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);

    /** The Customer closed the tab: the gateway is asked and answers for nobody, again and again, until the sweep gives up. */
    $gateway->fakeVerifiedStillPending($payment);

    sweepingPaymentsAfter($payment, PaymentSweep::EXPIRE_AFTER_SECONDS);

    expect($payment->refresh()->state)->toBe(PaymentState::Expired)
        ->and(TrackingEvent::count())->toBe(0);
})->with('tracking providers');

/**
 * Paid happens with no browser present — from the sweep, or from a gateway's
 * own Notification — so what the browser said at Capture has to have been
 * kept. It cannot be read back off the outbox, because such an Order writes
 * no row at Capture and so leaves none to copy from.
 */
it('reports the purchase with what the browser told us at capture, long after the browser has gone', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);

    recordCaptureOf($payment->order, [TrackingContext::CLICK_IDS_FIELD => $script->browserClickIds()], [
        'REMOTE_ADDR' => '203.0.113.42',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile',
    ]);

    $gateway->fakeVerifiedPaid($payment);

    /** Nobody is there: the gateway tells the system on its own, with no browser anywhere in the request. */
    $this->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk();

    $row = TrackingEvent::sole();

    expect($row->ip_address)->toBe('203.0.113.42')
        ->and($row->user_agent)->toBe('Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile')
        ->and($row->click_ids)->toBe($script->browserClickIds())

        /** The address the visitor ordered from, not the gateway's own. */
        ->and($row->source_url)->toBe('https://shop.test/checkout');
})->with('tracking providers');

/**
 * Kept where it will be needed and nowhere else. An Order reporting at
 * Capture has already written everything the browser said onto its own outbox
 * row, and a second copy would be a second thing to prune.
 */
it('keeps what the browser said for an order whose purchase is still to come', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);
    recordCaptureOf($payment->order, [TrackingContext::CLICK_IDS_FIELD => $script->browserClickIds()]);

    $context = TrackingContext::sole();

    expect($context->order_id)->toBe(Order::sole()->getKey())
        ->and($context->click_ids)->toBe($script->browserClickIds())
        ->and($context->source_url)->toBe('https://shop.test/checkout')
        ->and($context->ip_address)->not->toBeNull();
})->with('tracking providers');

it('keeps nothing for a cash on delivery order, which reported everything it had at capture', function (ProviderScript $script) {
    $script->makeDestination();

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::count())->toBe(1)
        ->and(TrackingContext::count())->toBe(0);
})->with('tracking providers');

/**
 * An IP address with no purpose is not collected at all. A store reporting
 * nowhere has nothing a click identifier could ever be used for.
 */
it('keeps nothing at all where no destination is configured', function () {
    $payment = capturedFor(new SslCommerzScript);
    recordCaptureOf($payment->order, [TrackingContext::CLICK_IDS_FIELD => ['fbc' => 'fb.1.1757000000000.IwAR0abc']]);

    expect(TrackingContext::count())->toBe(0)
        ->and(TrackingEvent::count())->toBe(0);
});

it('keeps nothing for a destination the merchant has switched off, because that is what a campaign break is', function () {
    Destination::factory()->disabled()->create();

    $payment = capturedFor(new SslCommerzScript);
    recordCaptureOf($payment->order, [TrackingContext::CLICK_IDS_FIELD => ['fbc' => 'fb.1.1757000000000.IwAR0abc']]);

    expect(TrackingContext::count())->toBe(0);
});

/**
 * The first visit's word is what stands. Whatever asks again is a later
 * request, and a Provider is owed the click that produced the Order rather
 * than the one that happened to re-submit it.
 */
it('keeps one context per order however many times the capture moment is recorded', function (ProviderScript $script) {
    $script->makeDestination();
    $gateway = new SslCommerzScript;

    $payment = capturedFor($gateway);
    recordCaptureOf($payment->order, [TrackingContext::CLICK_IDS_FIELD => $script->browserClickIds()]);

    $order = Order::sole();

    app(MomentRecorder::class)->captured($order, TrackingContext::none());

    expect(TrackingContext::count())->toBe(1)
        ->and(TrackingContext::sole()->click_ids)->toBe($script->browserClickIds());
})->with('tracking providers');
