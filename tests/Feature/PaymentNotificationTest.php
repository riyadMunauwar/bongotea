<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Payment;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Payments\GatewayScript;

/**
 * A Notification is a gateway telling the system, on its own, that something
 * happened to a Payment. It is a hint and never an answer: it names the
 * Payment, a Verification the system makes decides, and nothing the
 * Notification itself says is ever written down (ADR-0013).
 */
it('verifies with the gateway on a notification and marks the payment paid, so a customer who closed the browser still ends up paid', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    /** The Customer never came back: no return, no reference of the gateway's on the Payment. */
    expect($payment->gateway_reference)->toBeNull();

    $gateway->fakeVerifiedPaid($payment);

    $this->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk();

    $payment->refresh();

    expect($payment->state)->toBe(PaymentState::Paid)
        ->and($payment->verified_at)->not->toBeNull()
        ->and($payment->gateway_reference)->toBe($gateway->gatewayReference($payment))
        ->and($payment->verification_response)->not->toBeEmpty()
        ->and($payment->order->isPaid())->toBeTrue();

    $gateway->assertVerificationsSent(1);
})->with('online gateways');

it('never records what the notification says: a notification claiming paid that the gateway does not confirm leaves the payment unpaid', function (GatewayScript $gateway, string $fake, PaymentState $state) {
    $payment = capturedFor($gateway);

    $gateway->{$fake}($payment);

    /** Answered 200 whatever happened, so the gateway stops retrying. */
    $this->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk();

    /** Nor the reference it carried: a Payment holds only a reference the gateway itself named when asked. */
    expect($payment->refresh()->state)->toBe($state)
        ->and($payment->verified_at)->toBeNull()
        ->and($payment->gateway_reference)->toBeNull()
        ->and($payment->order->isPaid())->toBeFalse();
})->with('online gateways')->with([
    'the gateway says the transaction is invalid' => ['fakeVerifiedInvalid', PaymentState::Failed],
    'the gateway cannot be reached to confirm it' => ['fakeOutage', PaymentState::Pending],
]);

it('answers 200 and reports the failure when verifying throws, leaving the sweep to ask again', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake(fn () => throw new RuntimeException('The driver fell over.'));

    $this->withoutExceptionHandling();

    $this->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('marks paid once when a notification lands between the gateway\'s answer and the write', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $interrupted = false;

    /**
     * The first question to the gateway is interrupted by a Notification
     * arriving and verifying to the end before the answer is written; it
     * answers nothing itself, so the gateway's own fake beneath it does.
     */
    Http::fake(function () use (&$interrupted, $gateway, $payment) {
        if (! $interrupted) {
            $interrupted = true;
            test()->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk();
        }

        return null;
    });
    $gateway->fakeVerifiedPaid($payment);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();
    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.confirmation', $payment->order->order_number));

    /** Both asked; the second to write found nothing left to decide. */
    expect($interrupted)->toBeTrue()
        ->and(Payment::count())->toBe(1)
        ->and($payment->refresh()->state)->toBe(PaymentState::Paid)
        ->and($payment->order->isPaidTwice())->toBeFalse();
})->with('online gateways');

it('ignores and logs a notification naming a payment the system does not know, and answers 200', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake();
    Log::spy();

    $this->post(notificationUrl($payment), $gateway->notificationNamingNoPayment())->assertOk();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'no Payment'));

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('ignores and logs a notification claiming a different amount, and answers 200', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake();
    Log::spy();

    $this->post(notificationUrl($payment), $gateway->notificationWithMismatchedAmount($payment))->assertOk();

    /** Not even asked about: a hint that disagrees with the record is garbage, not a reason to spend a call. */
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'amount'));

    expect($payment->refresh()->state)->toBe(PaymentState::Pending)
        ->and($payment->gateway_reference)->toBeNull();
})->with('online gateways');

it('ignores a notification that names no payment at all, and answers 200', function (GatewayScript $gateway) {
    capturedFor($gateway);

    Http::fake();

    $this->post(notificationUrl(Payment::sole()), [])->assertOk();

    Http::assertNothingSent();

    expect(Payment::sole()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('answers 404 for a method that is not online, and hears but ignores a notification under another driver\'s address', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake();

    /** Every online method has a driver now; the route still 404s a method with none, but no case is one to prove it with. */
    $this->post('/pay/cash_on_delivery/notify', $gateway->paidNotification($payment))->assertNotFound();

    /** Another gateway's driver reads its own shapes off it, finds no Payment of its own, and is not asked about. */
    $otherDriver = collect(PaymentMethod::cases())
        ->first(fn (PaymentMethod $method): bool => $method->isOnline() && $method !== $gateway->method() && $method->gateway() !== null);

    $this->post(route('payment.notify', ['method' => $otherDriver->value]), $gateway->paidNotification($payment))->assertOk();

    Http::assertNothingSent();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('sits outside request-forgery protection and behind a rate limit', function (GatewayScript $gateway) {
    $route = Route::getRoutes()->getByName('payment.notify');

    expect($route->methods())->toBe(['POST'])
        ->and($route->excludedMiddleware())->toContain(PreventRequestForgery::class)
        ->and($route->gatherMiddleware())->toContain('throttle:payment-notification');
})->with('online gateways');

it('marks a payment paid exactly once however many of the three sources verify it', function (GatewayScript $gateway, array $sources) {
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedPaid($payment);

    foreach ($sources as $source) {
        match ($source) {
            'processing page' => (function () use ($gateway, $payment): void {
                $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();
                $this->postJson(verifyUrl($payment))->assertOk();
            })(),
            'notification' => $this->post(notificationUrl($payment), $gateway->paidNotification($payment))->assertOk(),
            'sweep' => (function () use ($payment): void {
                $this->travelTo($payment->created_at->addMinutes(3));
                $this->artisan('payments:sweep')->assertSuccessful();
            })(),
        };
    }

    /** Asked the gateway once; every later source found nothing left to decide. */
    $gateway->assertVerificationsSent(1);

    expect(Payment::count())->toBe(1)
        ->and(Payment::sole()->state)->toBe(PaymentState::Paid)
        ->and($payment->order->refresh()->isPaidTwice())->toBeFalse();
})->with('online gateways')->with([
    'notification, then the processing page, then the sweep' => [['notification', 'processing page', 'sweep']],
    'the processing page, then a notification, then the sweep' => [['processing page', 'notification', 'sweep']],
    'the sweep, then a notification, then the processing page' => [['sweep', 'notification', 'processing page']],
]);
