<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings\SmsSettings;
use App\Support\Sms\SendText;
use App\Support\Storefront\StorefrontShell;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\SteadfastScript;
use Tests\Fixtures\Sms\GioSmsScript;

/**
 * Order Texts and the new-Order Staff Alert, observed where they land: at the
 * SMS Gateway, through its faked HTTP client.
 *
 * A text is sent after the fact and never on the way to it, so every test
 * here drives the thing that happens to the Order — Capture, the operator's
 * confirmation, a Courier taking the parcel — and reads what the gateway was
 * asked to send.
 */
beforeEach(function () {
    $this->gateway = new GioSmsScript;
    $this->gateway->makeUsable();
    $this->gateway->fakeAccepted();

    app(SmsSettings::class)->with([
        'placedTemplate' => '{name} | {order_number} | {kilograms} | {total} | {link}',
        'confirmedTemplate' => 'confirmed {order_number}',
        'sentTemplate' => 'sent {order_number}',
        'cancelledTemplate' => 'cancelled {order_number}',
        'newOrderAlertTemplate' => 'new {order_number} {name} {kilograms} {total}',
        'staffNumbers' => [],
    ])->save();
});

/**
 * An Order of a hundred kilograms at ৳1,250.00 a kilogram, captured for
 * Rahim Uddin on 01712345678.
 */
function hundredKilogramOrder(): Order
{
    return orderAsCaptured(lines: [
        ['product' => Product::factory()->create(['price' => 125000]), 'kilograms' => 60],
        ['product' => Product::factory()->create(['price' => 125000]), 'kilograms' => 40],
    ]);
}

it('texts the Customer that their Order was placed, in the Merchant\'s words with every placeholder filled', function () {
    $order = hundredKilogramOrder();

    expect($this->gateway->sent())->toBe([[
        'to' => '8801712345678',
        'message' => "Rahim Uddin | {$order->order_number} | ১০০ | ৳১,২৫,০০০ | ".url(StorefrontShell::MY_ORDERS_PATH),
    ]]);
});

it('alerts every staff number to a new Order, and never the Customer', function () {
    app(SmsSettings::class)->with([
        'placedEnabled' => false,
        'staffNumbers' => ['01811111111', '01922222222'],
    ])->save();

    $order = hundredKilogramOrder();

    $alert = "new {$order->order_number} Rahim Uddin ১০০ ৳১,২৫,০০০";

    expect($this->gateway->sent())->toBe([
        ['to' => '8801811111111', 'message' => $alert],
        ['to' => '8801922222222', 'message' => $alert],
    ]);
});

it('sends nothing for a text or an alert that is switched off', function () {
    app(SmsSettings::class)->with([
        'placedEnabled' => false,
        'newOrderAlertEnabled' => false,
        'staffNumbers' => ['01811111111'],
    ])->save();

    hundredKilogramOrder();

    expect($this->gateway->sent())->toBe([]);
});

it('texts the Customer when an operator confirms or cancels their Order', function (OrderStatus $status, string $message) {
    app(SmsSettings::class)->with(['placedEnabled' => false])->save();
    $this->actingAs(User::factory()->owner()->create());

    $order = hundredKilogramOrder();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['status' => $status->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->gateway->sent())->toBe([['to' => '8801712345678', 'message' => "{$message} {$order->order_number}"]]);
})->with([
    'confirmed' => [OrderStatus::Confirmed, 'confirmed'],
    'cancelled' => [OrderStatus::Cancelled, 'cancelled'],
]);

it('texts nothing when an Order is saved without its status changing', function () {
    app(SmsSettings::class)->with(['placedEnabled' => false])->save();

    $order = hundredKilogramOrder();
    $order->update(['note' => 'Call after 5pm']);

    expect($this->gateway->sent())->toBe([]);
});

it('texts the Customer once a Courier has taken the parcel', function () {
    app(SmsSettings::class)->with(['placedEnabled' => false])->save();
    $this->actingAs(User::factory()->owner()->create());

    $courier = new SteadfastScript;
    $order = orderReadyToSend($courier, ['customer_phone' => '+8801712345678']);
    $courier->fakeBooked();

    sendWithCourier($order, $courier)->assertHasNoActionErrors();

    expect($this->gateway->sent())->toBe([['to' => '8801712345678', 'message' => "sent {$order->order_number}"]]);
});

it('captures the Order and moves it along whatever the gateway does', function (string $failure) {
    $this->actingAs(User::factory()->owner()->create());
    answeringAfresh();
    $this->gateway->{$failure}();

    $order = hundredKilogramOrder();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['status' => OrderStatus::Confirmed->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
})->with(['fakeOutage', 'fakeServerError', 'fakeAuthFailure']);

it('queues a text only once the change that owes it has committed', function () {
    Queue::fake();

    try {
        DB::transaction(function (): void {
            hundredKilogramOrder();

            throw new RuntimeException('The Capture around it failed.');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNothingPushed();

    hundredKilogramOrder();

    Queue::assertPushed(SendText::class, 1);
});

it('asks the gateway again later when it said nothing', function () {
    answeringAfresh();
    $this->gateway->fakeOutage();

    $job = (new SendText('01712345678', 'Hello'))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased();
});

it('gives up on a text the gateway refused, without failing the job', function () {
    answeringAfresh();
    $this->gateway->fakeValidationError();

    $job = (new SendText('01712345678', 'Hello'))->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased()->assertNotFailed();
});
