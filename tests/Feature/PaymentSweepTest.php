<?php

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Support\Payments\PaymentSweep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Payments\GatewayScript;

/**
 * The sweep: the third place a Verification runs from, for the Customer who
 * paid and closed the browser before the gateway could send them back, and
 * for a Payment nothing will ever answer for.
 */
it('re-verifies a pending payment older than two minutes and marks it paid when the gateway confirms it', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedPaid($payment);

    $this->travelTo($payment->created_at->addSeconds(PaymentSweep::RECHECK_AFTER_SECONDS + 1));

    $this->artisan('payments:sweep')
        ->expectsOutputToContain('Verified 1 pending Payment')
        ->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Paid)
        ->and($payment->verified_at)->not->toBeNull()
        ->and($payment->order->isPaid())->toBeTrue();

    $gateway->assertVerificationsSent(1);
})->with('online gateways');

it('leaves a pending payment younger than two minutes alone, because the customer may still be at the gateway', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake();

    $this->travelTo($payment->created_at->addSeconds(PaymentSweep::RECHECK_AFTER_SECONDS - 1));

    $this->artisan('payments:sweep')
        ->expectsOutputToContain('Verified 0 pending Payments')
        ->assertSuccessful();

    Http::assertNothingSent();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('leaves a payment the gateway still cannot answer for pending, to be asked again next time', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $gateway->fakeOutage();

    $this->travelTo($payment->created_at->addMinutes(10));

    $this->artisan('payments:sweep')->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('records what the gateway says of an abandoned payment: cancelled stays cancelled, not expired', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedCancelled($payment);

    $this->travelTo($payment->created_at->addMinutes(10));

    $this->artisan('payments:sweep')->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Cancelled)
        ->and($payment->verification_response)->not->toBeEmpty();
})->with('online gateways');

it('marks a pending payment older than a day expired after one final verification the gateway cannot answer', function (GatewayScript $gateway, string $fake) {
    $payment = capturedFor($gateway);

    $gateway->{$fake}($payment);

    $this->travelTo($payment->created_at->addSeconds(PaymentSweep::EXPIRE_AFTER_SECONDS + 1));

    $this->artisan('payments:sweep')
        ->expectsOutputToContain('Expired 1 Payment')
        ->assertSuccessful();

    /** Asked one last time — the answer is on the record — then given up on. */
    expect($payment->refresh()->state)->toBe(PaymentState::Expired)
        ->and($payment->verification_response)->not->toBeEmpty()
        ->and($payment->verified_at)->toBeNull()
        ->and($payment->order->isUnpaid())->toBeTrue();
})->with('online gateways')->with([
    'the gateway has nothing decided' => ['fakeVerifiedStillPending'],
    'the gateway cannot be reached' => ['fakeOutage'],
]);

it('marks a pending payment older than a day paid, not expired, when the final verification confirms it', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedPaid($payment);

    $this->travelTo($payment->created_at->addDays(2));

    $this->artisan('payments:sweep')
        ->expectsOutputToContain('Expired 0 Payments')
        ->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Paid);
})->with('online gateways');

it('lets an expired payment be retried from the retry page', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedStillPending($payment);
    $this->travelTo($payment->created_at->addDays(2));
    $this->artisan('payments:sweep')->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Expired);

    $order = $payment->order;

    $this->get(route('checkout.retry', $order->order_number))->assertOk()->assertSee('not yet paid');

    $gateway->fakeStart();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => $gateway->method()->value])
        ->assertStatus(303)
        ->assertRedirect($gateway->gatewayPageUrl());

    $retry = $order->payments()->get()->last();

    expect(Payment::count())->toBe(2)
        ->and($retry->state)->toBe(PaymentState::Pending)
        ->and($retry->invoice_reference)->toBe($order->order_number.'-2');
})->with('online gateways');

it('asks about nothing settled: paid, failed, cancelled and expired payments are not swept', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake();

    foreach ([PaymentState::Paid, PaymentState::Failed, PaymentState::Cancelled, PaymentState::Expired] as $state) {
        $payment->update(['state' => $state]);

        $this->travelTo($payment->created_at->addDays(2));

        $this->artisan('payments:sweep')->assertSuccessful();

        expect($payment->refresh()->state)->toBe($state);
    }

    Http::assertNothingSent();
})->with('online gateways');

it('runs every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'payments:sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
