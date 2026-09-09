<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderDocument;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Payments\GatewayScript;
use Tests\Fixtures\Payments\SslCommerzScript;

/**
 * Working paid and Unpaid Orders in the admin: the payment axis drawn on the
 * queue and the Order page, and the two things an operator does about it on
 * the call. The fulfilment axis is `OrderFulfilmentTest`'s and is untouched
 * here (ADR-0013).
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * An Order as Capture leaves it under the given method: one line, snapshotted
 * from a Product, and no Payment yet.
 */
function orderUnder(PaymentMethod $method, array $attributes = []): Order
{
    $order = Order::factory()->create(array_merge([
        'customer_name' => 'Rahim Uddin',
        'delivery_charge' => 6000,
        'payment_method' => $method,
    ], $attributes));

    OrderLine::factory()
        ->for($order)
        ->for(Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]))
        ->create(['product_name' => 'Winter Jacket', 'unit_price' => 99000, 'quantity' => 1]);

    return $order->refresh();
}

/**
 * An Order captured under the gateway with one Pending Payment for its total,
 * the shape the operator meets on a call about a Customer still at the gateway.
 */
function orderWithPendingPayment(GatewayScript $gateway, array $attributes = []): Order
{
    $gateway->makeUsable();

    $order = orderUnder($gateway->method(), $attributes);

    /** With the gateway's start answer on record, as a Capture would have left it: a driver may ask by what the start issued. */
    $payment = Payment::factory()->for($order)->create(['method' => $gateway->method()]);
    $payment->update(['start_response' => $gateway->startResponseFor($payment)]);

    return $order->refresh();
}

/**
 * The payment entries on an Order's trail, oldest first: the outcomes and
 * the checks, never the fulfilment edits.
 */
function paymentEntries(Order $order): Collection
{
    return Activity::query()
        ->where('subject_type', Order::class)
        ->where('subject_id', $order->getKey())
        ->where('event', 'like', 'payment_%')
        ->orderBy('id')
        ->get();
}

/*
|--------------------------------------------------------------------------
| The queue
|--------------------------------------------------------------------------
*/

it('shows each order\'s payment method and whether it is paid, unpaid or cash on delivery', function () {
    $cash = orderUnder(PaymentMethod::CashOnDelivery);
    $unpaid = orderUnder(PaymentMethod::SslCommerz);
    $paid = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($paid)->paid()->create();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$cash, $unpaid, $paid])
        ->assertTableColumnFormattedStateSet('payment_method', 'Cash on Delivery', $cash)
        ->assertTableColumnFormattedStateSet('payment_method', 'SSLCommerz · Unpaid', $unpaid)
        ->assertTableColumnFormattedStateSet('payment_method', 'SSLCommerz · Paid', $paid);
});

it('draws an unpaid order distinctly in the queue', function () {
    $unpaid = orderUnder(PaymentMethod::SslCommerz);
    $paid = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($paid)->paid()->create();
    $cash = orderUnder(PaymentMethod::CashOnDelivery);

    $column = Livewire::test(ListOrders::class)->instance()->getTable()->getColumn('payment_method');

    $colorFor = fn (Order $order): string|array|null => $column->record($order)->getColor($column->getState());

    expect($colorFor($unpaid))->toBe('danger')
        ->and($colorFor($paid))->toBe('success')
        ->and($colorFor($cash))->toBe('gray');
});

it('narrows the queue to the unpaid online orders to chase', function () {
    $unpaid = orderUnder(PaymentMethod::SslCommerz);
    $failed = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($failed)->create(['state' => PaymentState::Failed]);
    $paid = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($paid)->paid()->create();
    $cash = orderUnder(PaymentMethod::CashOnDelivery);

    Livewire::test(ListOrders::class)
        ->filterTable('unpaid')
        ->assertCanSeeTableRecords([$unpaid, $failed])
        ->assertCanNotSeeTableRecords([$paid, $cash]);
});

it('narrows the queue to the paid but cancelled orders to refund', function () {
    $paidAndCancelled = orderUnder(PaymentMethod::SslCommerz, ['status' => OrderStatus::Cancelled]);
    Payment::factory()->for($paidAndCancelled)->paid()->create();
    $paidAndNew = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($paidAndNew)->paid()->create();
    $unpaidAndCancelled = orderUnder(PaymentMethod::SslCommerz, ['status' => OrderStatus::Cancelled]);
    $cashAndCancelled = orderUnder(PaymentMethod::CashOnDelivery, ['status' => OrderStatus::Cancelled]);

    /** Both are kept: the operator's decision and the money's story (ADR-0013). */
    Livewire::test(ListOrders::class)
        ->filterTable('paid_but_cancelled')
        ->assertCanSeeTableRecords([$paidAndCancelled])
        ->assertCanNotSeeTableRecords([$paidAndNew, $unpaidAndCancelled, $cashAndCancelled]);

    expect($paidAndCancelled->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($paidAndCancelled->isPaid())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Order page
|--------------------------------------------------------------------------
*/

it('lists every payment on both screens with what the gateway said', function (string $page) {
    $order = orderUnder(PaymentMethod::SslCommerz);

    $failed = Payment::factory()->for($order)->create([
        'state' => PaymentState::Failed,
        'start_response' => ['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active'],
    ]);

    $paid = Payment::factory()->for($order)->paid()->create([
        'gateway_reference' => '2409081200001ABCDEF',
        'verification_response' => ['status' => 'VALID', 'tran_id' => $order->order_number.'-2'],
    ]);

    Livewire::test($page, ['record' => $order->getKey()])
        ->assertSeeText('Payments')
        ->assertSeeText($failed->invoice_reference)
        ->assertSeeText($paid->invoice_reference)
        ->assertSeeText('Failed')
        ->assertSeeText('Paid')
        ->assertSeeText(Money::format($paid->amount))
        ->assertSeeText('2409081200001ABCDEF')
        ->assertSeeText($paid->verified_at->format('M j, Y'))
        ->assertSeeText('Store Credential Error Or Store is De-active')
        ->assertSeeText('"status": "VALID"');
})->with([
    'reading it' => ViewOrder::class,
    'working it' => EditOrder::class,
]);

it('says on both screens that an unpaid order with no payment has none', function (string $page) {
    $order = orderUnder(PaymentMethod::SslCommerz);

    Livewire::test($page, ['record' => $order->getKey()])
        ->assertSeeText(OrderDocument::UNPAID_WARNING)
        ->assertSeeText('No payment has been started.');
})->with([
    'reading it' => ViewOrder::class,
    'working it' => EditOrder::class,
]);

it('draws no payments section for a cash on delivery order', function () {
    $order = orderUnder(PaymentMethod::CashOnDelivery);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertDontSeeText('Payments')
        ->assertDontSeeText(OrderDocument::UNPAID_WARNING);
});

/*
|--------------------------------------------------------------------------
| Check with gateway
|--------------------------------------------------------------------------
*/

it('checks a pending payment with the gateway during the call and records it paid', function (GatewayScript $gateway, string $page) {
    $order = orderWithPendingPayment($gateway);
    $payment = $order->payments()->sole();

    $gateway->fakeVerifiedPaid($payment);

    Livewire::test($page, ['record' => $order->getKey()])
        ->callAction('checkWithGateway')
        ->assertHasNoActionErrors()
        ->assertNotified('Paid');

    $payment->refresh();

    /** The driver's own Verification, recorded the way the Processing Page records one (ADR-0013). */
    expect($payment->state)->toBe(PaymentState::Paid)
        ->and($payment->verified_at)->not->toBeNull()
        ->and($payment->gateway_reference)->toBe($gateway->gatewayReference($payment))
        ->and($payment->verification_response)->not->toBeEmpty()
        ->and($order->refresh()->isPaid())->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::New);

    $gateway->assertVerificationsSent(1);
})->with('online gateways')->with([
    'reading it' => ViewOrder::class,
    'working it' => EditOrder::class,
]);

it('records a payment paid against a cancelled order without reopening it', function (GatewayScript $gateway) {
    $order = orderWithPendingPayment($gateway, ['status' => OrderStatus::Cancelled]);
    $payment = $order->payments()->sole();

    $gateway->fakeVerifiedPaid($payment);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('checkWithGateway')
        ->assertNotified('Paid');

    /** Both at once until somebody refunds: the operator's decision and the money's story (ADR-0013). */
    expect($payment->refresh()->state)->toBe(PaymentState::Paid)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->isPaid())->toBeTrue();

    Livewire::test(ListOrders::class)
        ->filterTable('paid_but_cancelled')
        ->assertCanSeeTableRecords([$order]);
})->with('online gateways');

it('keeps showing the payments, and paid, on an order switched to cash on delivery whose pending payment was then taken', function () {
    $order = orderWithPendingPayment(new SslCommerzScript);
    $order->update(['payment_method' => PaymentMethod::CashOnDelivery]);
    $order->payments()->sole()->update(['state' => PaymentState::Paid, 'verified_at' => now()]);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText('Payments')
        ->assertSeeText($order->payments()->sole()->invoice_reference)
        ->assertSeeText('Cash on Delivery · Paid');

    Livewire::test(ListOrders::class)
        ->assertTableColumnFormattedStateSet('payment_method', 'Cash on Delivery · Paid', $order);
});

it('leaves a payment the gateway cannot answer for pending and says so', function (GatewayScript $gateway) {
    $order = orderWithPendingPayment($gateway);
    $payment = $order->payments()->sole();

    $gateway->fakeOutage();

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('checkWithGateway')
        ->assertNotified('Still pending');

    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
})->with('online gateways');

it('records the check and its outcome on the order\'s trail, naming who asked', function (GatewayScript $gateway) {
    $order = orderWithPendingPayment($gateway);
    $payment = $order->payments()->sole();

    $gateway->fakeVerifiedCancelled($payment);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('checkWithGateway');

    $entries = paymentEntries($order);

    expect($entries->pluck('event')->all())->toBe(['payment_cancelled', 'payment_checked'])
        ->and($entries->every(fn (Activity $entry): bool => $entry->causer_id === auth()->id()))->toBeTrue()
        ->and($entries->first()->properties->get('payment'))->toBe($payment->invoice_reference)
        ->and($entries->last()->properties->get('state'))->toBe('cancelled');
})->with('online gateways');

it('offers the check only while a payment is pending', function () {
    $pending = orderWithPendingPayment(new SslCommerzScript);

    $settled = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($settled)->create(['state' => PaymentState::Failed]);

    Livewire::test(ViewOrder::class, ['record' => $pending->getKey()])
        ->assertActionVisible('checkWithGateway');

    Livewire::test(ViewOrder::class, ['record' => $settled->getKey()])
        ->assertActionHidden('checkWithGateway');

    Livewire::test(ViewOrder::class, ['record' => orderUnder(PaymentMethod::CashOnDelivery)->getKey()])
        ->assertActionHidden('checkWithGateway');
});

/*
|--------------------------------------------------------------------------
| Switch to Cash on Delivery
|--------------------------------------------------------------------------
*/

it('switches an unpaid order to cash on delivery from both screens and logs it', function (string $page) {
    Http::fake();

    $order = orderWithPendingPayment(new SslCommerzScript);

    Livewire::test($page, ['record' => $order->getKey()])
        ->callAction('switchToCashOnDelivery')
        ->assertHasNoActionErrors()
        ->assertNotified();

    $order->refresh();

    /** The Order's own trail records the switch, old and new, as the Retry Page's does; the figures do not move. */
    expect($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->isUnpaid())->toBeFalse()
        ->and($order->total)->toBe(105000)
        ->and($order->subtotal)->toBe(99000)
        ->and($order->delivery_charge)->toBe(6000);

    $entry = Activity::query()->where('subject_type', Order::class)->where('subject_id', $order->getKey())->where('event', 'updated')->latest('id')->firstOrFail();

    expect($entry->causer_id)->toBe(auth()->id())
        ->and($entry->attribute_changes->get('old'))->toBe(['payment_method' => 'sslcommerz'])
        ->and($entry->attribute_changes->get('attributes'))->toBe(['payment_method' => 'cash_on_delivery']);

    /** Nothing was said to the gateway: switching is not starting a Payment. */
    Http::assertNothingSent();
})->with([
    'reading it' => ViewOrder::class,
    'working it' => EditOrder::class,
]);

it('offers the switch only on an unpaid order', function () {
    $unpaid = orderUnder(PaymentMethod::SslCommerz);

    $paid = orderUnder(PaymentMethod::SslCommerz);
    Payment::factory()->for($paid)->paid()->create();

    Livewire::test(ViewOrder::class, ['record' => $unpaid->getKey()])
        ->assertActionVisible('switchToCashOnDelivery');

    Livewire::test(ViewOrder::class, ['record' => $paid->getKey()])
        ->assertActionHidden('switchToCashOnDelivery');

    Livewire::test(ViewOrder::class, ['record' => orderUnder(PaymentMethod::CashOnDelivery)->getKey()])
        ->assertActionHidden('switchToCashOnDelivery');
});

/*
|--------------------------------------------------------------------------
| Payment outcomes on the trail
|--------------------------------------------------------------------------
*/

it('records every payment outcome on the order\'s trail, whoever verified it', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);
    $order = $payment->order;

    $gateway->fakeVerifiedPaid($payment);

    /** The Processing Page's Verification: nobody is signed in on it. */
    auth()->logout();

    $this->postJson(verifyUrl($payment))->assertOk();

    $entry = paymentEntries($order)->sole();

    expect($entry->event)->toBe('payment_paid')
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->properties->get('payment'))->toBe($payment->invoice_reference)
        ->and($entry->properties->get('amount'))->toBe($payment->amount)
        ->and($entry->properties->get('gateway_reference'))->toBe($gateway->gatewayReference($payment));
})->with('online gateways');

it('records a payment that could not be started as failed on the trail', function (GatewayScript $gateway) {
    $gateway->fakeOutage();

    $this->postJson(route('checkout.store'), capturePayload(pageOffering($gateway), ['payment_method' => $gateway->method()->value]))
        ->assertCreated();

    $order = Order::sole();

    expect(paymentEntries($order)->sole()->event)->toBe('payment_failed')
        ->and(Payment::sole()->state)->toBe(PaymentState::Failed);
})->with('online gateways');

it('records nothing on the trail while a payment is still pending', function (GatewayScript $gateway) {
    $payment = capturedFor($gateway);

    $gateway->fakeVerifiedStillPending($payment);

    $this->postJson(verifyUrl($payment))->assertOk();

    expect(paymentEntries($payment->order))->toBeEmpty();
})->with('online gateways');
