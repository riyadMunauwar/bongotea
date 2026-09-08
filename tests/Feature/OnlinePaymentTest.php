<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payment;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Settings\PaymentSettings;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Payments\GatewayScript;
use Tests\Fixtures\Payments\SslCommerzScript;

/**
 * The road every gateway walks — the dataset, the Page offering it, the
 * Capture and the addresses — lives in `tests/Pest.php` and
 * `tests/Datasets/OnlineGateways.php`, shared with the Notification and sweep
 * tests, which walk the same road from the other end.
 */

/**
 * The same Page asking for a phone and nothing else, its payment section
 * carried through untouched.
 */
function askingOnlyForPhone(Page $page): Page
{
    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'fields' => array_map(
            fn (array $field): array => $field['key'] === 'phone' ? $field : [...$field, 'enabled' => false],
            $page->checkout_config->fieldsToArray(),
        ),
    ])]);

    return $page->refresh();
}

it('captures the order, starts a pending payment for its total, and sends the customer to the gateway', function (GatewayScript $gateway) {
    $gateway->fakeStart();

    $page = pageOffering($gateway);

    $response = $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => $gateway->method()->value]))
        ->assertCreated()
        ->assertJsonPath('redirect', $gateway->gatewayPageUrl());

    $order = Order::sole();
    $payment = Payment::sole();

    /** The Order is captured whole first, and the Payment hangs off it for exactly its total (ADR-0013). */
    expect($order->payment_method)->toBe($gateway->method())
        ->and($order->total)->toBe(105000)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->isUnpaid())->toBeTrue()
        ->and($payment->order_id)->toBe($order->getKey())
        ->and($payment->method)->toBe($gateway->method())
        ->and($payment->state)->toBe(PaymentState::Pending)
        ->and($payment->amount)->toBe(105000)
        ->and($payment->currency)->toBe('BDT')
        ->and($payment->invoice_reference)->toBe($order->order_number.'-1')
        ->and($payment->gateway_reference)->toBeNull()
        ->and($payment->verified_at)->toBeNull()
        ->and($payment->start_response)->not->toBeEmpty()
        ->and($payment->verification_response)->toBeNull();

    /** The mandatory fields were filled from the Order, and every way back is the Processing Page addressed by our reference. */
    $gateway->assertStartedFor($payment, processingUrl($payment), notificationUrl($payment));
})->with('online gateways');

it('answers a plain form post with a 303 to the gateway', function (GatewayScript $gateway) {
    $gateway->fakeStart();

    $this->post(route('checkout.store'), capturePayload(pageOffering($gateway), ['payment_method' => $gateway->method()->value]))
        ->assertStatus(303)
        ->assertRedirect($gateway->gatewayPageUrl());

    expect(Payment::sole()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('fills fixed placeholders for the fields the checkout did not ask, rather than asking for the gateway\'s sake', function (GatewayScript $gateway) {
    $gateway->fakeStart();

    $page = pageOffering($gateway);

    /** A checkout asking for a phone and nothing else. */
    $this->postJson(route('checkout.store'), capturePayload(askingOnlyForPhone($page), [
        'payment_method' => $gateway->method()->value,
        'name' => null,
        'address' => null,
        'district' => null,
    ]))->assertCreated();

    $order = Order::sole();

    expect($order->customer_name)->toBeNull()
        ->and($order->customer_address)->toBeNull();

    /** Every mandatory field was sent filled: a gateway refusing a blank is the Customer refused for a question the Merchant chose not to ask. */
    Http::assertSent(fn ($request): bool => $request['tran_id'] === Payment::sole()->invoice_reference
        && filled($request['cus_name'])
        && filled($request['cus_email'])
        && filled($request['cus_add1'])
        && filled($request['cus_city'])
        && $request['cus_phone'] === $order->customer_phone);
})->with('online gateways');

it('marks the payment failed and lands on the retry page with the order kept when the gateway cannot be reached at capture', function (GatewayScript $gateway) {
    $gateway->fakeOutage();

    $page = pageOffering($gateway);

    $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => $gateway->method()->value]))
        ->assertCreated()
        ->assertJsonPath('redirect', route('checkout.retry', Order::sole()->order_number));

    $payment = Payment::sole();

    /** Could not start, never Paid: the Order stands, the attempt is recorded Failed, and nothing was collected. */
    expect(Order::sole()->isUnpaid())->toBeTrue()
        ->and($payment->state)->toBe(PaymentState::Failed)
        ->and($payment->start_response)->not->toBeEmpty();

    $this->get(route('checkout.retry', Order::sole()->order_number))->assertOk()->assertSee($gateway->method()->getLabel());
})->with('online gateways');

it('marks the payment failed and lands on the retry page when the gateway refuses to start it', function (GatewayScript $gateway) {
    $gateway->fakeStartRefused();

    $this->post(route('checkout.store'), capturePayload(pageOffering($gateway), ['payment_method' => $gateway->method()->value]))
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', Order::sole()->order_number));

    expect(Payment::sole()->state)->toBe(PaymentState::Failed)
        ->and(Payment::sole()->start_response)->not->toBeEmpty();
})->with('online gateways');

it('renders the processing page on the gateway\'s return, outside request-forgery protection, by our reference alone', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    /** A cross-site POST with no session and no token, the way a gateway sends a browser back. */
    $response = $this->post(processingUrl($payment), $gateway->paidReturn($payment))
        ->assertOk()
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('verifying your payment')
        ->assertSee(verifyUrl($payment), escape: false)
        ->assertSee('http-equiv="refresh"', escape: false)
        ->assertDontSee('Rahim Uddin')
        ->assertDontSee('01712345678');

    /** The return is read for the gateway's reference and nothing more: the state waits for a Verification. */
    expect($payment->refresh()->gateway_reference)->toBe($gateway->gatewayReference($payment))
        ->and($payment->state)->toBe(PaymentState::Pending);

    /** A refresh of the same address is harmless. */
    $this->get(processingUrl($payment))->assertOk()->assertSee('verifying your payment');

    expect($response->getContent())->toContain('role="status"');
})->with('online gateways');

it('verifies with the gateway, marks the payment paid, and sends the customer to the thank-you page showing paid', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $gateway->fakeVerifiedPaid($payment);

    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.confirmation', $payment->order->order_number));

    $payment->refresh();
    $order = $payment->order->refresh();

    expect($payment->state)->toBe(PaymentState::Paid)
        ->and($payment->verified_at)->not->toBeNull()
        ->and($payment->gateway_reference)->toBe($gateway->gatewayReference($payment))
        ->and($payment->verification_response)->not->toBeEmpty()
        ->and($order->isPaid())->toBeTrue()
        ->and($order->isUnpaid())->toBeFalse()
        /** Paid is the Payment's axis; the Order still starts New and needs the confirmation call (ADR-0013). */
        ->and($order->status)->toBe(OrderStatus::New);

    $this->get(route('checkout.confirmation', $order->order_number))
        ->assertOk()
        ->assertSee('Your order is confirmed')
        ->assertSee('Paid')
        ->assertSee($gateway->method()->getLabel())
        ->assertDontSee(route('checkout.retry', $order->order_number));

    /** Nothing to retry once Paid. */
    $this->get(route('checkout.retry', $order->order_number))
        ->assertRedirect(route('checkout.confirmation', $order->order_number));
})->with('online gateways');

it('verifies once however often the processing page is refreshed, and never marks paid twice', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $gateway->fakeVerifiedPaid($payment);

    $this->postJson(verifyUrl($payment))->assertOk();
    $this->postJson(verifyUrl($payment))->assertOk()->assertJsonPath('redirect', route('checkout.confirmation', $payment->order->order_number));
    $this->get(verifyUrl($payment))->assertRedirect(route('checkout.confirmation', $payment->order->order_number));

    /** Asked the gateway once; a Paid Payment is not asked about again. */
    $gateway->assertVerificationsSent(1);

    expect(Payment::count())->toBe(1)
        ->and(Payment::sole()->state)->toBe(PaymentState::Paid);
})->with('online gateways');

it('answers a client without script with a redirect from the verification endpoint', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $gateway->fakeVerifiedPaid($payment);

    $this->get(verifyUrl($payment))
        ->assertStatus(303)
        ->assertRedirect(route('checkout.confirmation', $payment->order->order_number));

    expect($payment->refresh()->state)->toBe(PaymentState::Paid);
})->with('online gateways');

it('marks the payment failed and lands on the retry page when the gateway does not confirm it', function (GatewayScript $gateway, string $fake) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->failedReturn($payment))->assertOk();

    $gateway->{$fake}($payment);

    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.retry', $payment->order->order_number));

    $payment->refresh();

    expect($payment->state)->toBe(PaymentState::Failed)
        ->and($payment->verified_at)->toBeNull()
        ->and($payment->verification_response)->not->toBeEmpty()
        ->and($payment->order->isUnpaid())->toBeTrue()
        ->and($payment->order->status)->toBe(OrderStatus::New);

    $this->get(route('checkout.retry', $payment->order->order_number))->assertOk()->assertSee('not yet paid');
})->with('online gateways')->with([
    'the gateway says the transaction is invalid' => ['fakeVerifiedInvalid'],
    'the gateway confirms a smaller amount than asked for' => ['fakeVerifiedShort'],
]);

it('never settles a payment on a reference that names another transaction, and asks by our reference instead', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    /** A return carrying an id that is real, but some other Payment's: a forged or mistaken post to a guessable address. */
    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $gateway->fakeVerifiedForAnotherPayment($payment);

    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.confirmation', $payment->order->order_number));

    /** Asked twice — once by the id, once by our reference — and settled only on the answer that named this Payment. */
    $gateway->assertVerificationsSent(2);

    expect($payment->refresh()->state)->toBe(PaymentState::Paid)
        ->and($payment->verification_response['element'][0]['tran_id'] ?? null)->toBe($payment->invoice_reference);
})->with('online gateways');

it('marks the payment cancelled and lands on the retry page when the customer cancelled at the gateway', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    /** Nothing was attempted, so the return names no reference of the gateway's; the Verification asks by ours. */
    $this->post(processingUrl($payment), $gateway->cancelledReturn($payment))->assertOk();

    expect($payment->refresh()->gateway_reference)->toBeNull();

    $gateway->fakeVerifiedCancelled($payment);

    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.retry', $payment->order->order_number));

    expect($payment->refresh()->state)->toBe(PaymentState::Cancelled)
        ->and($payment->verification_response)->not->toBeEmpty()
        ->and($payment->order->isUnpaid())->toBeTrue();
})->with('online gateways');

it('leaves the payment pending and lands on the retry page when the gateway cannot be reached for verification', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    $gateway->fakeOutage();

    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.retry', $payment->order->order_number));

    /** Still Pending, never Failed: a moment's outage is for the sweep to retry, not a reason to send a Customer who paid to the Retry Page for good. */
    expect($payment->refresh()->state)->toBe(PaymentState::Pending)
        ->and($payment->verified_at)->toBeNull()
        ->and($payment->order->isUnpaid())->toBeTrue();
})->with('online gateways');

it('starts a new payment from the retry page for a chosen online method, with the next invoice reference', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->failedReturn($payment))->assertOk();
    $gateway->fakeVerifiedInvalid($payment);
    $this->postJson(verifyUrl($payment))->assertOk();

    expect($payment->refresh()->state)->toBe(PaymentState::Failed);

    $gateway->fakeStart();

    $order = $payment->order;

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => $gateway->method()->value])
        ->assertStatus(303)
        ->assertRedirect($gateway->gatewayPageUrl());

    $retry = Payment::query()->latest('id')->firstOrFail();

    expect(Payment::count())->toBe(2)
        ->and($retry->state)->toBe(PaymentState::Pending)
        ->and($retry->invoice_reference)->toBe($order->order_number.'-2')
        ->and($retry->amount)->toBe($order->total)
        ->and($order->refresh()->payment_method)->toBe($gateway->method());

    $gateway->assertStartedFor($retry, processingUrl($retry), notificationUrl($retry));
})->with('online gateways');

it('switches to cash on delivery from the retry page without starting a payment', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    Http::fake();

    $this->post(route('checkout.retry.choose', $payment->order->order_number), ['payment_method' => 'cash_on_delivery'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.confirmation', $payment->order->order_number));

    Http::assertNothingSent();

    expect(Payment::count())->toBe(1)
        ->and($payment->order->refresh()->isUnpaid())->toBeFalse();
})->with('online gateways');

it('starts no payment and calls no gateway for a method the page lists but can no longer offer', function (GatewayScript $gateway) {
    Http::fake();

    $page = pageOffering($gateway);
    $payload = capturePayload($page, ['payment_method' => $gateway->method()->value]);

    app(PaymentSettings::class)->with([PaymentSettings::property($gateway->method(), 'Enabled') => false])->save();

    $this->postJson(route('checkout.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('redirect', route('checkout.retry', Order::sole()->order_number));

    Http::assertNothingSent();

    expect(Payment::count())->toBe(0)
        ->and(Order::sole()->isUnpaid())->toBeTrue();
})->with('online gateways');

it('answers 404 to a processing or verification address that names no payment, or names one under another method', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(route('payment.processing', ['method' => $gateway->method()->value, 'payment' => 'NOSUCH01-1']), $gateway->paidReturn($payment))
        ->assertNotFound();

    $this->postJson(route('payment.verify', ['method' => $gateway->method()->value, 'payment' => 'NOSUCH01-1']))
        ->assertNotFound();

    $other = collect(PaymentMethod::cases())
        ->first(fn (PaymentMethod $method): bool => $method->isOnline() && $method !== $gateway->method());

    $this->post(route('payment.processing', ['method' => $other->value, 'payment' => $payment->invoice_reference]))
        ->assertNotFound();

    /** A method that is not online has no Processing Page at all. */
    $this->post('/pay/cash_on_delivery/'.$payment->invoice_reference)->assertNotFound();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('keeps the order status and the paid payment apart: a paid order is new, and confirming it changes no payment', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();
    $gateway->fakeVerifiedPaid($payment);
    $this->postJson(verifyUrl($payment))->assertOk();

    $order = $payment->order->refresh();

    expect($order->status)->toBe(OrderStatus::New)->and($order->isPaid())->toBeTrue();

    $order->update(['status' => OrderStatus::Confirmed]);

    expect($order->refresh()->isPaid())->toBeTrue()
        ->and($payment->refresh()->state)->toBe(PaymentState::Paid);
})->with('online gateways');

it('goes to the live host once the sandbox switch is off', function () {
    $gateway = new SslCommerzScript;
    $gateway->fakeStart();

    Http::fake(['securepay.sslcommerz.com/*' => Http::response([
        'status' => 'SUCCESS',
        'sessionkey' => 'LIVEKEY',
        'GatewayPageURL' => 'https://securepay.sslcommerz.com/EasyCheckOut/testcdeLIVEKEY',
    ])]);

    $page = pageOffering($gateway);

    app(PaymentSettings::class)->with(['sslcommerzSandbox' => false])->save();

    $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => 'sslcommerz']))
        ->assertCreated()
        ->assertJsonPath('redirect', 'https://securepay.sslcommerz.com/EasyCheckOut/testcdeLIVEKEY');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://securepay.sslcommerz.com/gwprocess/v4/api.php'));
});

/**
 * A second Pending Payment on the Order, started once the first is old
 * enough to be retried: the Customer back on the Retry Page a minute later
 * choosing the gateway again, with the first attempt still unanswered.
 */
function secondPaymentStartedAfterTheWait(GatewayScript $gateway, Payment $first): Payment
{
    test()->travel(Payment::RETRY_WAIT_SECONDS + 1)->seconds();

    $gateway->fakeStart();

    test()->post(route('checkout.retry.choose', $first->order->order_number), ['payment_method' => $gateway->method()->value])
        ->assertStatus(303)
        ->assertRedirect($gateway->gatewayPageUrl());

    /** The relation reads oldest first; the newest is the one just started. */
    return $first->order->payments()->get()->last();
}

it('refuses a second payment while one is pending and only seconds old, and says so on the retry page', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $order = $payment->order;

    /** Tapped again with the first attempt seconds old: refused beside the chooser, no Payment made, no gateway spoken to. */
    $this->from(route('checkout.retry', $order->order_number))
        ->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => $gateway->method()->value])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', $order->order_number))
        ->assertSessionHasErrors(['payment_method' => 'A payment you started a moment ago is still being confirmed. Please wait a minute before trying again.']);

    /** Followed the way a browser follows it, the Retry Page says so beside the chooser. */
    $this->followingRedirects()
        ->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => $gateway->method()->value])
        ->assertOk()
        ->assertSee('not yet paid')
        ->assertSee('still being confirmed');

    expect(Payment::count())->toBe(1)
        ->and($payment->refresh()->state)->toBe(PaymentState::Pending);

    /** Asked once, at Capture; the refused attempt never reached the gateway. */
    Http::assertSentCount(1);

    /** A minute on, the same choice starts the next attempt. */
    $retry = secondPaymentStartedAfterTheWait($gateway, $payment);

    expect(Payment::count())->toBe(2)
        ->and($retry->state)->toBe(PaymentState::Pending)
        ->and($retry->invoice_reference)->toBe($order->order_number.'-2');
})->with('online gateways');

it('still lets the customer switch to cash on delivery while a payment is pending and seconds old', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(route('checkout.retry.choose', $payment->order->order_number), ['payment_method' => 'cash_on_delivery'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.confirmation', $payment->order->order_number));

    expect($payment->order->refresh()->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and(Payment::count())->toBe(1);
})->with('online gateways');

it('records a second paid payment as paid and flags the order as paid twice', function (GatewayScript $gateway) {
    $first = capturedFor($gateway);
    $second = secondPaymentStartedAfterTheWait($gateway, $first);

    $order = $first->order;

    /** Both attempts come back taken: the Customer paid in two tabs, or twice from the gateway's app. */
    foreach ([$first, $second] as $payment) {
        $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();
        $gateway->fakeVerifiedPaid($payment);
        $this->postJson(verifyUrl($payment))
            ->assertOk()
            ->assertJsonPath('redirect', route('checkout.confirmation', $order->order_number));
    }

    /** Money moved twice, so both are recorded Paid as they are — and the Order is flagged, because someone must refund one. */
    expect($first->refresh()->state)->toBe(PaymentState::Paid)
        ->and($second->refresh()->state)->toBe(PaymentState::Paid)
        ->and($order->refresh()->isPaid())->toBeTrue()
        ->and($order->isPaidTwice())->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::New);

    $flag = Activity::query()->where('subject_type', Order::class)->where('subject_id', $order->getKey())->where('event', 'paid_twice')->sole();

    expect($flag->description)->toContain('paid twice')
        ->and($flag->properties->get('payments'))->toBe([$first->invoice_reference, $second->invoice_reference]);
})->with('online gateways');

it('does not read one paid payment as paid twice', function () {
    $order = Payment::factory()->paid()->create()->order;

    expect($order->isPaid())->toBeTrue()
        ->and($order->isPaidTwice())->toBeFalse()
        ->and(Activity::query()->where('event', 'paid_twice')->exists())->toBeFalse();
});

it('starts no payment for an order that is already paid, and sends the customer to the thank-you page', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();
    $gateway->fakeVerifiedPaid($payment);
    $this->postJson(verifyUrl($payment))->assertOk();

    $order = $payment->order;

    $this->travel(Payment::RETRY_WAIT_SECONDS + 1)->seconds();

    Http::fake();

    /** A Retry Page left open in another tab, posted after the money moved: nothing to collect, so nothing is started. */
    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => $gateway->method()->value])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.confirmation', $order->order_number));

    Http::assertNothingSent();

    expect(Payment::count())->toBe(1)
        ->and($order->refresh()->payment_method)->toBe($gateway->method());
})->with('online gateways');

it('records paid against a cancelled order and leaves it cancelled, so the order reads as both', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    /** The operator cancelled while the Customer was at the gateway. */
    $payment->order->update(['status' => OrderStatus::Cancelled]);

    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();
    $gateway->fakeVerifiedPaid($payment);
    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.confirmation', $payment->order->order_number));

    $order = $payment->order->refresh();

    /** The operator's decision and the money's story are both kept, until somebody refunds (ADR-0013). */
    expect($payment->refresh()->state)->toBe(PaymentState::Paid)
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->isPaid())->toBeTrue()
        ->and($order->isUnpaid())->toBeFalse();
})->with('online gateways');

it('never marks paid on a success return the verification does not confirm, whatever the return says', function (GatewayScript $gateway, string $fake, PaymentState $state) {
    $payment = capturedFor($gateway);

    /** A return claiming success in every field a gateway writes — replayed, forged, or real but unconfirmable. */
    $this->post(processingUrl($payment), $gateway->paidReturn($payment))->assertOk();

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);

    $gateway->{$fake}($payment);

    $this->postJson(verifyUrl($payment))
        ->assertOk()
        ->assertJsonPath('redirect', route('checkout.retry', $payment->order->order_number));

    expect($payment->refresh()->state)->toBe($state)
        ->and($payment->verified_at)->toBeNull()
        ->and($payment->order->isPaid())->toBeFalse()
        ->and($payment->order->isUnpaid())->toBeTrue();
})->with('online gateways')->with([
    'the gateway says the transaction is invalid' => ['fakeVerifiedInvalid', PaymentState::Failed],
    'the gateway cannot be reached to confirm it' => ['fakeOutage', PaymentState::Pending],
]);
