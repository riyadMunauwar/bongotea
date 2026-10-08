<?php

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Payments\Bkash;
use App\Support\Settings\PaymentSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Payments\BkashScript;

/**
 * What is bKash's alone, beside the road every gateway walks in
 * `OnlinePaymentTest`: the grant token and its cache, the hosts the Merchant
 * types, and the subscription its webhook opens with.
 *
 * `Http::fake()` stacks, the first fake to answer wins, and every call of it
 * restarts the recording — so a test that changes the gateway's mind partway
 * flips a flag its one fake reads, and lays a fake that answers nothing only
 * to start counting afresh.
 */
const BKASH_SANDBOX = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

/**
 * A grant that answers a fresh token each time it is asked, so the count of
 * grants and the token each call carried can both be read back.
 */
function fakeBkashGrantCounting(): void
{
    $grants = 0;

    Http::fake(function (Request $request) use (&$grants) {
        if (! str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/token/grant')) {
            return null;
        }

        $grants++;

        return Http::response(['statusCode' => '0000', 'statusMessage' => 'Successful', 'id_token' => "token-{$grants}", 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => 'r']);
    });
}

function fakeBkashCreate(): void
{
    Http::fake(fn (Request $request) => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/create')
        ? Http::response(['statusCode' => '0000', 'statusMessage' => 'Successful', 'paymentID' => 'TR0011'.$request['merchantInvoiceNumber'], 'bkashURL' => 'https://sandbox.payment.bkash.com/?hash=x', 'transactionStatus' => 'Initiated'])
        : null);
}

function grantsSent(): int
{
    return Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/token/grant'))->count();
}

it('grants one token with the merchant credentials as headers and reuses it for every payment within the hour', function () {
    $gateway = new BkashScript;

    fakeBkashGrantCounting();
    fakeBkashCreate();

    $first = capturedFor($gateway);

    $this->travel(Payment::RETRY_WAIT_SECONDS + 1)->seconds();

    $this->post(route('checkout.retry.choose', $first->order->order_number), ['payment_method' => 'bkash'])->assertStatus(303);

    /** The grant, once, as bKash documents it: username and password as headers, key and secret in the body. */
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/token/grant')
        && $request->hasHeader('username', 'sandboxTestUser')
        && $request->hasHeader('password', 'sandbox_pw_1')
        && $request['app_key'] === 'app_key_test_1'
        && $request['app_secret'] === 'app_secret_test_1');

    expect(grantsSent())->toBe(1)
        ->and(Payment::count())->toBe(2);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/create')
        && $request->hasHeader('Authorization', 'token-1'));

    /** Kept for just under the hour bKash issues it for, then granted afresh: the second grant, carried by the next create. */
    $this->travel(Bkash::TOKEN_TTL_SECONDS + 1)->seconds();

    fakeBkashGrantCounting();

    $this->post(route('checkout.retry.choose', $first->order->order_number), ['payment_method' => 'bkash'])->assertStatus(303);

    expect(grantsSent())->toBe(1);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/create')
        && $request->hasHeader('Authorization', 'token-2'));
});

it('refreshes the token once when bKash no longer honours it, and takes a second refusal as the answer', function () {
    $gateway = new BkashScript;

    fakeBkashGrantCounting();

    $answered = 0;
    $honoursNoToken = false;

    /** The cached token is stale: the first create is refused with 401, the retry with a fresh token gets through. */
    Http::fake(function (Request $request) use (&$answered, &$honoursNoToken, $gateway) {
        if (! str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/create')) {
            return null;
        }

        $answered++;

        return $request->hasHeader('Authorization', 'token-2') && ! $honoursNoToken
            ? Http::response(['statusCode' => '0000', 'paymentID' => 'TR0011X', 'bkashURL' => $gateway->gatewayPageUrl()])
            : Http::response(['message' => 'Unauthorized'], 401);
    });

    payingOnline($gateway)
        ->assertStatus(303)
        ->assertRedirect($gateway->gatewayPageUrl());

    $order = Payment::sole()->order;

    expect(grantsSent())->toBe(2)
        ->and($answered)->toBe(2)
        ->and(Payment::sole()->state)->toBe(PaymentState::Pending);

    /** Refused again with the fresh token: could not start, and no third grant is spent on it. */
    Cache::flush();
    $honoursNoToken = true;
    fakeBkashGrantCounting();

    $this->travel(Payment::RETRY_WAIT_SECONDS + 1)->seconds();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'bkash'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', $order->order_number));

    expect(grantsSent())->toBe(2)
        ->and(Payment::count())->toBe(2)
        ->and(Payment::query()->latest('id')->firstOrFail()->state)->toBe(PaymentState::Failed);
});

it('marks the payment failed at capture and leaves it pending at verification when bKash refuses to grant a token', function () {
    $gateway = new BkashScript;

    $refusesToGrant = true;

    Http::fake(function (Request $request) use (&$refusesToGrant) {
        return str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/token/grant') && $refusesToGrant
            ? Http::response(['statusCode' => '9999', 'statusMessage' => 'System Error'])
            : null;
    });

    payingOnline($gateway)
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', Order::sole()->order_number));

    $failed = Payment::sole();

    expect($failed->state)->toBe(PaymentState::Failed)
        ->and($failed->start_response['statusMessage'] ?? null)->toBe('System Error');

    /** A Payment already started, asked about while the grant is refused: still Pending, for the sweep to ask again. */
    $refusesToGrant = false;
    $gateway->fakeStart();

    payingOnline($gateway)->assertRedirect();

    $pending = Payment::query()->latest('id')->firstOrFail();

    expect($pending->state)->toBe(PaymentState::Pending);

    Cache::flush();
    $refusesToGrant = true;

    $this->post(processingUrl($pending), $gateway->paidReturn($pending))->assertOk();
    $this->postJson(verifyUrl($pending))->assertOk()->assertJsonPath('redirect', route('checkout.retry', $pending->order->order_number));

    expect($pending->refresh()->state)->toBe(PaymentState::Pending)
        ->and($pending->verification_response['statusMessage'] ?? null)->toBe('System Error');
});

it('speaks to the host the Merchant typed, live once the sandbox switch is off', function () {
    $gateway = new BkashScript;
    $gateway->fakeStart();

    Http::fake(['live.bkash.example/*' => Http::response(['statusCode' => '0000', 'id_token' => 'live-token', 'paymentID' => 'TR0011LIVE', 'bkashURL' => 'https://payment.bkash.com/?hash=live'])]);

    $gateway->makeUsable();

    app(PaymentSettings::class)->with(['bkashSandbox' => false, 'bkashLiveHost' => 'https://live.bkash.example/v1.2.0-beta/'])->save();

    payingOnline($gateway)
        ->assertStatus(303)
        ->assertRedirect('https://payment.bkash.com/?hash=live');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://live.bkash.example/v1.2.0-beta/tokenized/checkout/token/grant');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://live.bkash.example/v1.2.0-beta/tokenized/checkout/create'
        && $request->hasHeader('Authorization', 'live-token'));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX));
});

it('confirms a webhook subscription by visiting its SNS address, and only an SNS address', function () {
    $gateway = new BkashScript;
    $payment = capturedFor($gateway);

    Http::fake(['sns.ap-southeast-1.amazonaws.com/*' => Http::response('<ConfirmSubscriptionResponse/>')]);

    $subscribeUrl = 'https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn:aws:sns:ap-southeast-1:000000000000:bkash-webhook&Token=abc';

    /** Posted the way SNS posts it: JSON under a text content type, with the type in a header. */
    $this->call('POST', notificationUrl($payment), [], [], [], [
        'CONTENT_TYPE' => 'text/plain; charset=UTF-8',
        'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => 'SubscriptionConfirmation',
    ], json_encode(['Type' => 'SubscriptionConfirmation', 'SubscribeURL' => $subscribeUrl, 'Token' => 'abc']))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $subscribeUrl);

    /** A forged envelope naming some other address has this site fetch nothing. */
    Log::spy();

    $this->post(notificationUrl($payment), ['Type' => 'SubscriptionConfirmation', 'SubscribeURL' => 'https://evil.example/confirm'])->assertOk();

    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'not SNS'));

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
});

it('reads a notification posted as raw JSON under a text content type, the way SNS posts it', function () {
    $gateway = new BkashScript;
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedPaid($payment);

    $this->call('POST', notificationUrl($payment), [], [], [], [
        'CONTENT_TYPE' => 'text/plain; charset=UTF-8',
        'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => 'Notification',
    ], json_encode($gateway->paidNotification($payment)))->assertOk();

    expect($payment->refresh()->state)->toBe(PaymentState::Paid);

    $gateway->assertVerificationsSent(1);
});

it('does not execute again once an execute has been refused, and asks the status query alone from then on', function () {
    $gateway = new BkashScript;
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $completed = false;

    /** Executed, refused as not yet authorised; the status query says Initiated until bKash later says Completed. */
    Http::fake(function (Request $request) use (&$completed, $payment, $gateway) {
        if (str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/execute')) {
            return Http::response(['statusCode' => '2060', 'statusMessage' => 'The payment execution prerequisites are not met']);
        }

        if (str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/payment/status')) {
            return Http::response([
                'statusCode' => '0000',
                'paymentID' => $gateway->gatewayReference($payment),
                'merchantInvoice' => $payment->invoice_reference,
                'transactionStatus' => $completed ? 'Completed' : 'Initiated',
                'amount' => Money::toMajorString($payment->amount),
                'currency' => 'BDT',
            ]);
        }

        return null;
    });

    $this->postJson(verifyUrl($payment))->assertOk()->assertJsonPath('redirect', route('checkout.retry', $payment->order->order_number));

    expect($payment->refresh()->state)->toBe(PaymentState::Pending)
        ->and($payment->verification_response['execute']['statusCode'] ?? null)->toBe('2060')
        ->and($payment->verification_response['transactionStatus'] ?? null)->toBe('Initiated');

    $gateway->assertVerificationsSent(2);

    /** The sweep, later: the status query only, still Initiated — and the refusal stays on the record under the new Pending answer. */
    fakeBkashGrantCounting();

    $this->travelTo($payment->created_at->addMinutes(3));
    $this->artisan('payments:sweep')->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending)
        ->and($payment->verification_response['execute']['statusCode'] ?? null)->toBe('2060');

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/execute'));
    $gateway->assertVerificationsSent(1);

    /** The sweep again, once bKash says Completed: still the status query alone, never a second execute. */
    $completed = true;
    fakeBkashGrantCounting();

    $this->travelTo($payment->created_at->addMinutes(6));
    $this->artisan('payments:sweep')->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Paid);

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), BKASH_SANDBOX.'/tokenized/checkout/execute'));
    $gateway->assertVerificationsSent(1);
});
