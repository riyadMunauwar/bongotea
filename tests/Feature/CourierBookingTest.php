<?php

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Couriers\CourierBooker;
use App\Support\Couriers\CourierNotUsable;
use App\Support\Couriers\OrderAlreadyShipping;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * Booking one Order with one Courier: the action on the Order's screens, the
 * call, and the Shipment that records the attempt.
 *
 * Every case runs once per Courier over `tests/Datasets/CourierScripts.php`,
 * so a Courier added later is a script and a row rather than a file — the
 * same seam the gateways and the tracking providers are tested through. No
 * test here reaches for a driver: the couriers' asymmetries are asked of the
 * script, never branched on by name.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * An Order as Capture leaves it, with the Courier switched on and supplied:
 * one line, a Customer to deliver to, and nothing sent yet.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderReadyToSend(CourierScript $script, array $attributes = []): Order
{
    $script->makeUsable();

    $order = Order::factory()->create(array_merge([
        'customer_name' => 'Rahim Uddin',
        'customer_address' => 'House 4, Road 12, Banani',
        'customer_district' => 'Dhaka',
        'delivery_charge' => 6000,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ], $attributes));

    OrderLine::factory()
        ->for($order)
        ->for(Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]))
        ->create(['product_name' => 'Winter Jacket', 'unit_price' => 99000, 'quantity' => 1]);

    return $order->refresh();
}

/**
 * Press "Send with courier" on one of the Order's screens, choosing this
 * Courier, the way a User on the call does.
 */
function sendWithCourier(Order $order, CourierScript $script, string $page = ViewOrder::class): Testable
{
    return Livewire::test($page, ['record' => $order->getKey()])
        ->callAction('bookWithCourier', ['courier' => $script->courier()->value]);
}

/**
 * Forget every fake laid so far, so one test can hear the Courier answer two
 * different ways.
 *
 * `Http::fake()` stacks and the first callback to answer wins — a refusal
 * faked before an acceptance answers for both — and nothing clears the stubs,
 * so the client itself is replaced. Stray requests stay barred.
 */
function courierAnsweringAfresh(): void
{
    app()->forgetInstance(Http::class);

    HttpFacade::clearResolvedInstances();
    HttpFacade::preventStrayRequests();
}

/**
 * What the User was last told, read the way the panel reads it — out of the
 * session, which the read empties. Asked once in a test, never beside
 * `assertNotified()`, which empties it too.
 */
function lastNotification(): ?Notification
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications->last();
}

/*
|--------------------------------------------------------------------------
| Booking
|--------------------------------------------------------------------------
*/

it('books an order with a courier and shows the consignment and tracking code straight away', function (CourierScript $script, string $page) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script, $page)
        ->assertHasNoActionErrors()
        ->assertNotified('Sent with '.$script->courier()->getLabel());

    $shipment = Shipment::sole();

    /** What came back is on the record and in front of the User who asked for it (ADR-0020). */
    expect($shipment->order_id)->toBe($order->getKey())
        ->and($shipment->courier)->toBe($script->courier())
        ->and($shipment->consignment_reference)->toBe($script->consignmentReference())
        ->and($shipment->tracking_code)->toBe($script->trackingCode())
        ->and($shipment->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->raw_status)->not->toBeNull()
        ->and($shipment->booked_at)->not->toBeNull()
        ->and($shipment->booking_response)->not->toBeEmpty()
        ->and($shipment->isActive())->toBeTrue()
        ->and($order->refresh()->activeShipment?->getKey())->toBe($shipment->getKey());

    $script->assertBookingsSent(1);
})->with('courier scripts')->with([
    'reading it' => ViewOrder::class,
    'working it' => EditOrder::class,
]);

it('sends every field the courier calls mandatory, filled from the order', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    $script->assertBookedFor(Shipment::sole());
})->with('courier scripts');

it('snapshots the recipient as this attempt was told it, so a later correction cannot rewrite what went out', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    $shipment = Shipment::sole();

    expect($shipment->recipient_name)->toBe('Rahim Uddin')
        ->and($shipment->recipient_phone)->toBe($order->customer_phone)
        ->and($shipment->recipient_address)->toBe('House 4, Road 12, Banani')
        ->and($shipment->recipient_district)->toBe('Dhaka');

    /** The Order moves on; the attempt keeps what it was sent with (ADR-0018). */
    $order->update(['customer_address' => 'House 9, Road 3, Uttara']);

    expect($shipment->refresh()->recipient_address)->toBe('House 4, Road 12, Banani');
})->with('courier scripts');

it('writes the first event with our own time, and the courier\'s where the courier gives one', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    $event = Shipment::sole()->events()->sole();

    expect($event->status)->toBe(ShipmentStatus::Booked)
        ->and($event->raw_status)->toBe(Shipment::sole()->raw_status)
        ->and($event->recorded_at)->not->toBeNull()
        ->and($event->happenedAt())->not->toBeNull();
})->with('courier scripts');

it('never touches the order\'s own status', function (CourierScript $script, OrderStatus $status) {
    $order = orderReadyToSend($script, ['status' => $status]);

    $script->fakeBooked();

    sendWithCourier($order, $script)->assertNotified();

    /** The two axes never write each other, in either direction (ADR-0018). */
    expect($order->refresh()->status)->toBe($status)
        ->and(Shipment::sole()->status)->toBe(ShipmentStatus::Booked);
})->with('courier scripts')->with([
    'a new order' => OrderStatus::New,
    'a confirmed order' => OrderStatus::Confirmed,
    'an order a user cancelled' => OrderStatus::Cancelled,
]);

/*
|--------------------------------------------------------------------------
| What the courier is told to collect
|--------------------------------------------------------------------------
*/

it('tells the courier to collect the whole total, delivery charge included, when nothing has been paid', function (CourierScript $script, PaymentMethod $method) {
    $order = orderReadyToSend($script, ['payment_method' => $method]);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    /** 990.00 for the jacket and 60.00 to get it there. An Unpaid Order that chose to pay online ships with the full total. */
    expect($order->refresh()->total)->toBe(105000)
        ->and(Shipment::sole()->collect_amount)->toBe(105000);
})->with('courier scripts')->with([
    'due at the door' => PaymentMethod::CashOnDelivery,
    'chosen online and never paid' => PaymentMethod::SslCommerz,
]);

it('tells the courier to collect nothing when the order already has a paid payment', function (CourierScript $script) {
    $order = orderReadyToSend($script, ['payment_method' => PaymentMethod::SslCommerz]);

    Payment::factory()->for($order)->create(['state' => PaymentState::Paid, 'amount' => $order->total]);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    expect($order->refresh()->isPaid())->toBeTrue()
        ->and(Shipment::sole()->collect_amount)->toBe(0);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| When the parcel does not go
|--------------------------------------------------------------------------
*/

it('words a courier\'s refusal back to the user and records nothing as sent', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBookingRefused();

    sendWithCourier($order, $script)
        ->assertNotified($script->courier()->getLabel().' refused this parcel');

    expect(Shipment::count())->toBe(0)
        ->and($order->refresh()->isShipping())->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::New);
})->with('courier scripts');

it('says what the courier said, in the courier\'s own words', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBookingRefused();

    sendWithCourier($order, $script);

    /** The Courier's own sentence, not ours: an operator acts on what the Courier objected to. */
    expect(lastNotification()?->getBody())->toContain($script->refusalWording());
})->with('courier scripts');

it('leaves the order exactly as it was when the courier cannot be reached', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeOutage();

    sendWithCourier($order, $script)
        ->assertNotified($script->courier()->getLabel().' could not be reached');

    expect(Shipment::count())->toBe(0)
        ->and($order->refresh()->isShipping())->toBeFalse();
})->with('courier scripts');

it('offers no courier at all until one is switched on and supplied', function (CourierScript $script) {
    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('bookWithCourier');

    $script->makeUsable();

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('bookWithCourier');
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| One parcel at a time
|--------------------------------------------------------------------------
*/

it('will not send a second parcel while one is still out', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    /** The action is gone from the screen, and the booking itself refuses against a fresh read. */
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('bookWithCourier');

    app(CourierBooker::class)->book($order, $script->courier());
})->with('courier scripts')->throws(OrderAlreadyShipping::class);

it('sends a second attempt under a reference the courier will accept once the first parcel is over', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    $first = Shipment::sole();

    expect($first->invoice_reference)->toBe($order->order_number.'-1');

    /** The parcel came back. The attempt is over, the Order may be sent again, and the first row stays as it is. */
    $first->update(['status' => ShipmentStatus::Returned, 'raw_status' => $script->returnedWording()]);

    courierAnsweringAfresh();
    $script->fakeBooked();

    sendWithCourier($order->refresh(), $script)->assertNotified();

    $second = $order->refresh()->shipments()->get()->last();

    /** Distinct, because a Courier refuses a duplicate invoice at exactly the moment a User is rescuing an Order (ADR-0018). */
    expect($order->shipments()->count())->toBe(2)
        ->and($second->invoice_reference)->toBe($order->order_number.'-2')
        ->and($second->invoice_reference)->not->toBe($first->invoice_reference)
        ->and($first->refresh()->status)->toBe(ShipmentStatus::Returned);
})->with('courier scripts');

it('counts an attempt a courier refused as no attempt at all', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBookingRefused();
    sendWithCourier($order, $script);

    courierAnsweringAfresh();
    $script->fakeBooked();
    sendWithCourier($order->refresh(), $script);

    /** Nothing was recorded for the refusal, so the reference it would have taken is still free. */
    expect(Shipment::sole()->invoice_reference)->toBe($order->order_number.'-1');
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| The couriers' own vocabularies
|--------------------------------------------------------------------------
*/

it('translates every one of a courier\'s own status words into the canonical vocabulary', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    foreach (ShipmentStatus::cases() as $status) {
        foreach ($script->rawStatusesFor($status) as $rawStatus) {
            $order->shipments()->delete();

            courierAnsweringAfresh();
            $script->fakeBookedReporting($rawStatus);

            sendWithCourier($order->refresh(), $script);

            expect(Shipment::sole()->status)->toBe($status, "[{$rawStatus}] did not read as {$status->value}.")
                ->and(Shipment::sole()->raw_status)->toBe($rawStatus);
        }
    }
})->with('courier scripts');

it('reads a parcel the courier calls cancelled as returned, and never as the order\'s own cancelled', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBookedReporting($script->returnedWording());

    sendWithCourier($order, $script);

    /** Two of the three couriers say "cancelled" for a parcel that came back. The Order's own Cancelled is a User's decision (ADR-0018). */
    expect(Shipment::sole()->status)->toBe(ShipmentStatus::Returned)
        ->and(Shipment::sole()->raw_status)->toBe($script->returnedWording())
        ->and($order->refresh()->status)->toBe(OrderStatus::New);
})->with('courier scripts');

it('declares what it can be asked, so nothing has to know which courier it is', function (CourierScript $script) {
    $driver = $script->courier()->driver();

    expect($driver->supportsBulk())->toBe($script->supportsBulk())
        ->and($driver->supportsWebhook())->toBe($script->supportsWebhook())
        ->and($driver->supportsBalance())->toBe($script->supportsBalance())
        ->and($driver->needsAreaResolution())->toBe($script->needsAreaResolution())
        ->and($driver->batchLimit())->toBe($script->batchLimit());
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| The shipment's own record
|--------------------------------------------------------------------------
*/

it('refuses the same happening twice at the database, not at the call site', function () {
    $shipment = Shipment::factory()->create();

    $first = $shipment->recordEvent(ShipmentStatus::OnHold, 'hold', '2026-09-12T10:00:00Z');
    $again = $shipment->recordEvent(ShipmentStatus::OnHold, 'hold', '2026-09-12T10:00:00Z');

    expect($again->getKey())->toBe($first->getKey())
        ->and($shipment->events()->count())->toBe(1);

    /** The constraint and not the check: an insert that dodges `recordEvent()` is refused all the same. */
    expect(fn () => ShipmentEvent::factory()->for($shipment)->create([
        'status' => ShipmentStatus::OnHold,
        'raw_status' => 'hold',
        'occurred_at' => '2026-09-12T10:00:00Z',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps the courier\'s time and ours apart, and draws the courier\'s where there is one', function () {
    $shipment = Shipment::factory()->create();

    $announced = $shipment->recordEvent(ShipmentStatus::Delivered, 'delivered', '2026-09-11T09:30:00Z');
    $asked = $shipment->recordEvent(ShipmentStatus::OnHold, 'hold');

    expect($announced->occurred_at->toAtomString())->toBe('2026-09-11T09:30:00+00:00')
        ->and($announced->recorded_at)->not->toBeNull()
        ->and($announced->happenedAt())->toEqual($announced->occurred_at)
        ->and($asked->occurred_at)->toBeNull()
        ->and($asked->happenedAt())->toEqual($asked->recorded_at);
});

it('reads a shipment as active until the courier is finished with it or a user writes it off', function () {
    $out = Shipment::factory()->create();
    $delivered = Shipment::factory()->delivered()->create();
    $returned = Shipment::factory()->returned()->create();
    $abandoned = Shipment::factory()->abandoned()->create();

    expect($out->isActive())->toBeTrue()
        ->and($delivered->isActive())->toBeFalse()
        ->and($returned->isActive())->toBeFalse()
        ->and($abandoned->isActive())->toBeFalse()
        ->and(Shipment::query()->active()->pluck('id')->all())->toBe([$out->getKey()]);

    foreach ([$delivered, $returned, $abandoned] as $over) {
        expect($over->order->isShipping())->toBeFalse();
    }
});

it('dials a stored number the way a domestic courier takes it', function () {
    expect(PhoneNumber::asDialled('+8801712345678'))->toBe('01712345678')
        ->and(PhoneNumber::asDialled('01712-345678'))->toBe('01712345678')
        ->and(PhoneNumber::asDialled('nonsense'))->toBeNull();
});

it('says a courier whose booking is not built yet cannot be used, and never that it refused', function () {
    app(CourierSettings::class)->with([
        'pathaoEnabled' => true,
        'pathaoClientId' => 'supplied',
        'pathaoClientSecret' => 'supplied',
        'pathaoUsername' => 'supplied',
        'pathaoPassword' => 'supplied',
    ])->save();

    app()->forgetInstance(CourierSettings::class);

    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('bookWithCourier', ['courier' => Courier::Pathao->value]);

    $notification = lastNotification();

    /** Nobody asked Pathao anything, so nothing says Pathao refused: an operator must not ring a Courier about a setting. */
    expect(Shipment::count())->toBe(0)
        ->and($notification?->getTitle())->toBe('Pathao cannot be used')
        ->and($notification?->getBody())->toContain('cannot be booked from here yet');
});

it('asks whether a courier can be used at the booking itself, not only at the screen that offered it', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    /**
     * The screen was drawn while the Courier was usable and the Merchant
     * switched it off in between — which no screen can be asked about, so it
     * is asked of the booking, under the Order's lock.
     */
    app(CourierSettings::class)->with([CourierSettings::property($script->courier(), 'Enabled') => false])->save();
    app()->forgetInstance(CourierSettings::class);

    $refusal = null;

    try {
        app(CourierBooker::class)->book($order, $script->courier());
    } catch (CourierNotUsable $exception) {
        $refusal = $exception;
    }

    /** The one wording Settings gives, and not a word about the Courier refusing: nobody asked it anything. */
    expect($refusal?->getMessage())->toBe($script->courier()->getLabel().' is switched off in Settings.')
        ->and(Shipment::count())->toBe(0);
})->with('courier scripts');
