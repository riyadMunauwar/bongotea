<?php

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Checkout\CheckoutFieldType;
use App\Support\Couriers\CourierBooker;
use App\Support\Couriers\CourierNotUsable;
use App\Support\Couriers\DeliveryArea;
use App\Support\Couriers\DeliveryAreaNotResolved;
use App\Support\Couriers\OrderAlreadyShipping;
use App\Support\Couriers\ParcelDetails;
use App\Support\Money;
use App\Support\Orders\CapturedAnswer;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;
use Tests\Fixtures\Couriers\PathaoScript;
use Tests\Fixtures\Couriers\RedExScript;

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
 * Press "Send with courier" on one of the Order's screens, choosing this
 * Courier, the way a User on the call does.
 *
 * The queue reaches the same action by the row rather than by the header, so
 * a User working down a day's Orders sends each parcel without opening any of
 * them. One action, three screens, one dialog.
 */
function sendWithCourier(Order $order, CourierScript $script, string $page = ViewOrder::class, array $confirming = []): Testable
{
    $answers = array_merge(['courier' => $script->courier()->value], $confirming);

    if ($page === ListOrders::class) {
        return Livewire::test(ListOrders::class)->callTableAction('bookWithCourier', $order, $answers);
    }

    return Livewire::test($page, ['record' => $order->getKey()])
        ->callAction('bookWithCourier', $answers);
}

/**
 * Open the send dialog on this Courier without sending anything, the way a
 * User reading back what is about to go out does.
 */
function openSendDialog(Order $order, CourierScript $script): Testable
{
    return Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->mountAction('bookWithCourier')
        ->setActionData(['courier' => $script->courier()->value]);
}

/**
 * This Courier's own answers, where the dialog keeps them: under the Courier's
 * own name, so two couriers asking the same thing cannot read each other's.
 *
 * @param  array<string, string>  $answers
 * @return array<string, string>
 */
function answering(CourierScript $script, array $answers): array
{
    $keyed = [];

    foreach ($answers as $name => $answer) {
        $keyed["{$script->courier()->value}.{$name}"] = $answer;
    }

    return $keyed;
}

/**
 * An Order carrying the note its Customer wrote for the rider, which is a
 * Merchant's own question keyed `note` and not a column.
 */
function orderWithDeliveryNote(CourierScript $script, string $note): Order
{
    $order = orderReadyToSend($script);

    $order->update(['answers' => [
        new CapturedAnswer('note', 'Note for the courier', CheckoutFieldType::Textarea, $note),
    ]]);

    return $order->refresh();
}

/**
 * Every Courier on this installation switched on and supplied, so a dialog
 * claiming to draw only the chosen Courier's questions has the other two to be
 * wrong about.
 *
 * Built from each driver's own credential declaration rather than from a list
 * here, so a fourth Courier is covered the moment it exists.
 */
function makeEveryCourierUsable(): void
{
    $supplied = [];

    foreach (Courier::cases() as $courier) {
        $supplied[CourierSettings::property($courier, 'Enabled')] = true;

        foreach ($courier->driver()->credentialProperties() as $credential) {
            $supplied[$credential] = 'supplied';
        }
    }

    app(CourierSettings::class)->with($supplied)->save();

    app()->forgetInstance(CourierSettings::class);

    /**
     * And each of them answering with the delivery areas it serves, where it
     * has any. A Courier that will not read an address in free text cannot
     * have its questions drawn at all until it has said where it delivers, so
     * "every Courier offered at once" means every list answered too — asked of
     * the scripts rather than of the one Courier that has a list.
     */
    foreach (courierScripts() as $script) {
        $script->fakeAreas();
    }
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
    'from the queue, without opening it' => ListOrders::class,
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

it('offers no courier for an order that should never have existed', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $order->delete();

    /**
     * `OrderResource` drops the soft-delete scope, so a deleted Order opens on
     * every screen and shows on the queue under the Trashed filter — and the
     * booking would read it back under its lock through the scope still on
     * the model and refuse it after the User had filled the dialog in.
     * Restoring it first is the answer, and that action is on the same row.
     */
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('bookWithCourier');

    Livewire::test(ListOrders::class)
        ->filterTable('trashed', ['value' => true])
        ->assertTableActionHidden('bookWithCourier', $order);
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

    /** The action is gone from the screens and from the row, and the booking itself refuses against a fresh read. */
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('bookWithCourier');

    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('bookWithCourier', $order);

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

    answeringAfresh();
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

    answeringAfresh();
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

            answeringAfresh();
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

/*
|--------------------------------------------------------------------------
| The send dialog
|--------------------------------------------------------------------------
*/

it('shows what the courier will be told, and sends nothing until the user says so', function (CourierScript $script) {
    $order = orderWithDeliveryNote($script, 'Ring the bell twice.');

    openSendDialog($order, $script)
        ->assertActionDataSet([
            'recipientName' => 'Rahim Uddin',
            'recipientPhone' => $order->customer_phone,
            'recipientAddress' => 'House 4, Road 12, Banani',
            'recipientDistrict' => 'Dhaka',
            'note' => 'Ring the bell twice.',
        ])

        /** 990.00 for the jacket and 60.00 to get it there, said before the parcel goes rather than discovered after. */
        ->assertMountedActionModalSee(Money::format($order->total));

    expect(Shipment::count())->toBe(0);
})->with('courier scripts');

it('reads back what a blank detail will go out as, rather than an empty box', function (CourierScript $script) {
    $order = orderReadyToSend($script, ['customer_name' => null, 'customer_address' => null]);

    openSendDialog($order, $script)
        ->assertSchemaComponentExists(
            'recipientName',
            checkComponentUsing: fn (TextInput $field): bool => $field->getPlaceholder() === CourierBooker::PLACEHOLDER_NAME,
        )
        ->assertSchemaComponentExists(
            'recipientAddress',
            checkComponentUsing: fn (Textarea $field): bool => $field->getPlaceholder() === CourierBooker::PLACEHOLDER_ADDRESS,
        );
})->with('courier scripts');

it('sends a correction to the courier and saves it onto the order as well', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, [
        'recipientName' => 'Rahim Uddin Ahmed',
        'recipientAddress' => 'House 9, Road 3, Uttara',
        'recipientDistrict' => 'Gazipur',
    ])->assertNotified();

    $shipment = Shipment::sole();

    /** `assertBookedFor()` holds the request against the Shipment, so this is the corrected address reaching the Courier. */
    $script->assertBookedFor($shipment);

    expect($shipment->recipient_name)->toBe('Rahim Uddin Ahmed')
        ->and($shipment->recipient_address)->toBe('House 9, Road 3, Uttara')
        ->and($shipment->recipient_district)->toBe('Gazipur');

    /** And the next person to open the Order sees the address we now know is right. */
    expect($order->refresh()->customer_name)->toBe('Rahim Uddin Ahmed')
        ->and($order->customer_address)->toBe('House 9, Road 3, Uttara')
        ->and($order->customer_district)->toBe('Gazipur');
})->with('courier scripts');

it('leaves a detail the checkout never captured alone rather than reading a blank box as an erasure', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, [
        'recipientName' => '   ',
        'recipientAddress' => '',
    ])->assertNotified();

    expect($order->refresh()->customer_name)->toBe('Rahim Uddin')
        ->and($order->customer_address)->toBe('House 4, Road 12, Banani');
})->with('courier scripts');

it('sends a note a user wrote for this rider without rewriting what the customer wrote', function (CourierScript $script) {
    $order = orderWithDeliveryNote($script, 'Ring the bell twice.');

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, [
        'note' => 'Ring the bell twice. Call before 9pm.',
    ])->assertNotified();

    expect(Shipment::sole()->recipient_note)->toBe('Ring the bell twice. Call before 9pm.')

        /** The Customer's own answer to a Merchant's question, kept as they gave it (ADR-0006). */
        ->and($order->refresh()->deliveryNote())->toBe('Ring the bell twice.');
})->with('courier scripts');

it('keeps a correction on the order when the courier refuses the parcel', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBookingRefused();

    sendWithCourier($order, $script, ViewOrder::class, ['recipientAddress' => 'House 9, Road 3, Uttara'])
        ->assertNotified($script->courier()->getLabel().' refused this parcel');

    /**
     * A Courier usually refuses a parcel *because* the address was wrong, so a
     * correction rolled back with the refusal is the User's own work thrown
     * away at the moment they are rescuing the Order. Nothing was sent all the
     * same.
     */
    expect($order->refresh()->customer_address)->toBe('House 9, Road 3, Uttara')
        ->and(Shipment::count())->toBe(0)
        ->and($order->isShipping())->toBeFalse();
})->with('courier scripts');

it('keeps a correction on the order when the courier cannot be reached', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeOutage();

    sendWithCourier($order, $script, ViewOrder::class, ['recipientAddress' => 'House 9, Road 3, Uttara'])
        ->assertNotified($script->courier()->getLabel().' could not be reached');

    expect($order->refresh()->customer_address)->toBe('House 9, Road 3, Uttara')
        ->and(Shipment::count())->toBe(0);
})->with('courier scripts');

it('sends no note at all when the user clears the box', function (CourierScript $script) {
    $order = orderWithDeliveryNote($script, 'Ring the bell twice.');

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, ['note' => ''])->assertNotified();

    /** An emptied box means this parcel carries no note, never the Customer's note over again. */
    expect(Shipment::sole()->recipient_note)->toBeNull()
        ->and($order->refresh()->deliveryNote())->toBe('Ring the bell twice.');
})->with('courier scripts');

it('keeps each attempt as that attempt was told it, so two attempts on one order differ', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, ['recipientAddress' => 'House 4, Road 12, Banani']);

    $first = Shipment::sole();

    /** The parcel came back, the Customer gave a better address, and it goes again. */
    $first->update(['status' => ShipmentStatus::Returned, 'raw_status' => $script->returnedWording()]);

    answeringAfresh();
    $script->fakeBooked();

    sendWithCourier($order->refresh(), $script, ViewOrder::class, ['recipientAddress' => 'House 9, Road 3, Uttara'])
        ->assertNotified();

    $second = $order->refresh()->shipments()->get()->last();

    expect($first->refresh()->recipient_address)->toBe('House 4, Road 12, Banani')
        ->and($second->recipient_address)->toBe('House 9, Road 3, Uttara')
        ->and($order->customer_address)->toBe('House 9, Road 3, Uttara');
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| What each courier asks of its own
|--------------------------------------------------------------------------
*/

it('asks the chosen courier\'s own questions and no other courier\'s', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    /** Every Courier offered at once, so "only its own" is a claim with something to be wrong about. */
    makeEveryCourierUsable();

    $dialog = openSendDialog($order, $script);

    foreach (array_keys($script->parcelDefaults()) as $question) {
        $dialog->assertSchemaComponentVisible("{$script->courier()->value}.{$question}");
    }

    foreach (Courier::cases() as $courier) {
        if ($courier === $script->courier()) {
            continue;
        }

        foreach ($courier->driver()->parcelFields() as $field) {
            if (! $field->isWorthAsking()) {
                continue;
            }

            $dialog->assertSchemaComponentHidden("{$courier->value}.{$field->name}");
        }
    }
})->with('courier scripts');

it('opens the courier\'s own questions on what the merchant decided in settings', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    openSendDialog($order, $script)
        ->assertActionDataSet(answering($script, $script->parcelDefaults()))
        ->callMountedAction()
        ->assertNotified();

    $script->assertBookedWithParcelDetails($script->parcelDefaults());

    /**
     * Written onto the attempt, exactly: what the row says was sent is what
     * was sent, no more and no fewer keys. The row is the User's answers plus
     * whatever this Courier asks that nobody is ever shown — a pickup store
     * with one possible answer — which is why the script says what the whole
     * row should be rather than the test assuming it is the questions.
     */
    expect(Shipment::sole()->parcel_details)->toBe($script->parcelRowFor($script->parcelDefaults()));
})->with('courier scripts');

it('sends what a user overrode for this parcel, and leaves the installation\'s own defaults alone', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, answering($script, $script->parcelOverrides()))
        ->assertNotified();

    $script->assertBookedWithParcelDetails($script->parcelOverrides());

    $first = Shipment::sole();

    expect($first->parcel_details)->toBe($script->parcelRowFor($script->parcelOverrides()));

    /** The parcel came back and the next one goes out on the Merchant's defaults: the dialog configured nothing. */
    $first->update(['status' => ShipmentStatus::Returned, 'raw_status' => $script->returnedWording()]);

    answeringAfresh();
    $script->fakeBooked();

    sendWithCourier($order->refresh(), $script)->assertNotified();

    $script->assertBookedWithParcelDetails($script->parcelDefaults());
})->with('courier scripts');

it('never asks which store a parcel leaves from while the merchant has only one', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $dialog = openSendDialog($order, $script);

    /** A choice of one is not a choice; a Merchant with a second store starts being asked with no change to the dialog. */
    $script->pickupStoreCount() > 1
        ? $dialog->assertSchemaComponentVisible("{$script->courier()->value}.pickupStore")
        : $dialog->assertSchemaComponentDoesNotExist("{$script->courier()->value}.pickupStore");
})->with('courier scripts');

it('never asks which store a parcel leaves from, even of a courier that insists on one', function () {
    makeEveryCourierUsable();

    /** One warehouse each, which is what the Merchants this is for have. */
    $stores = [];
    $insisting = 0;

    foreach (Courier::cases() as $courier) {
        $stores[CourierSettings::property($courier, 'PickupStore')] = '12345';

        foreach ($courier->driver()->parcelFields() as $field) {
            $insisting += $field->name === 'pickupStore' ? 1 : 0;
        }
    }

    app(CourierSettings::class)->with($stores)->save();
    app()->forgetInstance(CourierSettings::class);

    /** Or this proves nothing: some Courier in the registry must actually have a store to be asked about. */
    expect($insisting)->toBeGreaterThan(0);

    $dialog = Livewire::test(ViewOrder::class, ['record' => Order::factory()->create()->getKey()])
        ->mountAction('bookWithCourier');

    foreach (Courier::cases() as $courier) {
        $dialog->assertSchemaComponentDoesNotExist("{$courier->value}.pickupStore");
    }
});

it('offers no courier the installation cannot speak to, and says why in settings rather than in the dialog', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    /** Every other Courier switched on and left unsupplied: meant to be used, and not usable yet. */
    $enabled = [];

    foreach (Courier::cases() as $courier) {
        if ($courier !== $script->courier()) {
            $enabled[CourierSettings::property($courier, 'Enabled')] = true;
        }
    }

    app(CourierSettings::class)->with($enabled)->save();
    app()->forgetInstance(CourierSettings::class);

    openSendDialog($order, $script)
        ->assertSchemaComponentExists(
            'courier',
            checkComponentUsing: fn (Select $chooser): bool => array_keys($chooser->getOptions()) === [$script->courier()->value],
        );

    /** And the refusal itself is worded in one place, which is Settings, in the words every screen draws. */
    foreach (Courier::cases() as $courier) {
        if ($courier !== $script->courier()) {
            expect(app(CourierSettings::class)->refusal($courier))->toBe("{$courier->getLabel()} has blank credentials in Settings.");
        }
    }
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| Where the parcel is going
|--------------------------------------------------------------------------
|
| One Courier of the three refuses an address in free text and insists on an
| area out of a list only it holds. Every case here is run over the whole
| dataset all the same, and asks the script whether its Courier needs an area
| rather than which Courier it is — so the two that resolve a destination
| themselves prove they are never held up by any of it, and a fourth Courier
| that does insist is covered the moment it has a script.
|
*/

it('guesses the delivery area a courier insists on, shows it pre-selected, and sends the one the user chooses instead', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $area = "{$script->courier()->value}.".DeliveryArea::ANSWER;

    $dialog = openSendDialog($order, $script);

    if (! $script->needsAreaResolution()) {
        /** A Courier that reads the address itself is asked nothing, and a box nobody has to answer is not drawn. */
        $dialog->assertSchemaComponentDoesNotExist($area);

        return;
    }

    $guess = $script->guessedArea();
    $chosen = $script->correctedArea();

    /**
     * Guessed from "House 4, Road 12, Banani" and put in front of the User
     * already chosen, because the common case is that it is right and they
     * have the Customer on the phone — and searchable, because the Courier
     * lists thousands and the guess is only a guess.
     */
    $dialog->assertActionDataSet(answering($script, [DeliveryArea::ANSWER => $guess->id]))
        ->assertSchemaComponentExists(
            $area,
            checkComponentUsing: fn (Select $field): bool => $field->isSearchable() && $field->isRequired(),
        );

    $script->fakeBooked();

    sendWithCourier($order, $script, ViewOrder::class, answering($script, [DeliveryArea::ANSWER => $chosen->id]))
        ->assertNotified();

    /** A guess and never a decision: what the User corrected it to is what the attempt records and what went out. */
    expect(Shipment::sole()->parcel_details)->toMatchArray($chosen->asParcelDetails());

    $script->assertBookedFor(Shipment::sole());
})->with('courier scripts');

it('refuses a parcel locally when a courier insists on a delivery area and none was chosen', function (CourierScript $script) {
    $order = orderReadyToSend($script);

    $script->fakeBooked();

    $refusal = null;

    try {
        app(CourierBooker::class)->book($order, $script->courier(), ParcelDetails::confirmed());
    } catch (DeliveryAreaNotResolved $exception) {
        $refusal = $exception;
    }

    if (! $script->needsAreaResolution()) {
        /** A Courier that resolves the destination from the address has nothing here to be held up by. */
        expect($refusal)->toBeNull()
            ->and(Shipment::count())->toBe(1);

        return;
    }

    /**
     * Refused before anything was sent, and not by the Courier: a booking with
     * a blank area comes back rejected, which reads to an operator as the
     * Courier refusing their parcel and sends them to ring it about a box on
     * our own screen.
     */
    expect($refusal?->getMessage())->toContain('delivery area')
        ->and(Shipment::count())->toBe(0)
        ->and($order->refresh()->isShipping())->toBeFalse();

    $script->assertBookingsSent(0);
})->with('courier scripts');

it('opens on no area at all, and sends nothing, when nothing about the order resolves one', function (CourierScript $script) {
    /** A landmark for an address and a District this Courier serves several areas in: there is nothing here to guess from. */
    $order = orderReadyToSend($script, ['customer_address' => 'Beside the big mosque, opposite the bank']);

    $script->fakeBooked();

    if (! $script->needsAreaResolution()) {
        /** A Courier that reads the address itself takes this Order exactly as it takes any other. */
        sendWithCourier($order, $script)->assertNotified();

        return;
    }

    $area = "{$script->courier()->value}.".DeliveryArea::ANSWER;

    /** No guess rather than the first area in the list, and the parcel stopped at the screen rather than sent to it. */
    openSendDialog($order, $script)->assertActionDataSet([$area => null]);

    sendWithCourier($order, $script)->assertHasActionErrors([$area]);

    expect(Shipment::count())->toBe(0)
        ->and($order->refresh()->isShipping())->toBeFalse();

    $script->assertBookingsSent(0);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| RedEx's own delivery areas
|--------------------------------------------------------------------------
|
| One Courier's own machinery, and so one of the two sections here that names
| a Courier. Everything above is a shared body run once per Courier over the
| dataset; a list fetched, held and spent has no outside to share, and the
| whole point of it is that a Merchant never learns there is one.
|
*/

it('asks redex for its delivery areas once and addresses every parcel after that from the list it kept', function () {
    $script = new RedExScript;

    $first = orderReadyToSend($script);
    $second = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($first, $script)->assertNotified();
    sendWithCourier($second, $script)->assertNotified();

    $script->assertBookingsSent(2);

    /**
     * Held between requests. RedEx lists thousands of areas and has no bulk
     * endpoint, so a two-hundred-parcel batch is two hundred bookings — and
     * would be two hundred area lists as well if this were asked per parcel.
     */
    $script->assertAreasFetched(1);

    foreach (Shipment::all() as $shipment) {
        $script->assertBookedFor($shipment);
    }
});

it('tells redex the area a user confirmed, by the id it routes on and the name it prints', function () {
    $script = new RedExScript;

    $order = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($order, $script)->assertNotified();

    /**
     * Both, and both recorded, at the head of the row: RedEx routes on the id
     * and prints the name, and a name looked up afresh when the parcel goes
     * could be a name the cached list has since changed. What the row says was
     * sent is what was sent — exactly, no more keys and no fewer.
     */
    expect(Shipment::sole()->parcel_details)->toBe($script->parcelRowFor($script->parcelDefaults()));

    $script->assertBookedFor(Shipment::sole());
});

/*
|--------------------------------------------------------------------------
| Pathao's own access token
|--------------------------------------------------------------------------
|
| The other of the two sections here that names a Courier, and for the same
| reason. Everything above is a shared body run once per Courier over the
| dataset, because every Courier books a parcel the same way from the outside;
| a token issued, held and renewed has no outside to share. Pathao is the only
| one of the three that must be authenticated before it can be asked anything,
| and the whole point of this is that a Merchant never learns that.
|
*/

it('issues pathao one access token and signs every parcel with it', function () {
    $script = new PathaoScript;

    $first = orderReadyToSend($script);
    $second = orderReadyToSend($script);

    $script->fakeBooked();

    sendWithCourier($first, $script)->assertNotified();
    sendWithCourier($second, $script)->assertNotified();

    $script->assertBookingsSent(2);

    /** Held between requests: a second parcel a moment later does not cost a second grant. */
    $script->assertTokensIssued(1);

    /** And both went out under it — `assertBookedFor()` holds the bearer header against each attempt. */
    foreach (Shipment::all() as $shipment) {
        $script->assertBookedFor($shipment);
    }
});

it('keeps pathao\'s access token out of the merchant\'s settings entirely', function () {
    $script = new PathaoScript;

    $script->fakeBooked();

    sendWithCourier(orderReadyToSend($script), $script)->assertNotified();

    app()->forgetInstance(CourierSettings::class);

    /**
     * Derived state a machine issued and will age out, not a credential a
     * Merchant owns — so a settings group is not where it belongs. Read across
     * every property rather than naming one, because a token saved under any
     * name at all is the thing this is here to catch.
     */
    expect(collect((array) app(CourierSettings::class))->containsStrict($script->issuedAccessToken()))->toBeFalse()
        ->and(CourierSettings::secrets())->not->toContain('pathaoAccessToken');
});

it('records what pathao said the delivery would cost, in minor units', function () {
    $script = new PathaoScript;

    $script->fakeBooked();

    sendWithCourier(orderReadyToSend($script), $script)->assertNotified();

    /** Pathao quotes a delivery fee in taka at booking; the Shipment keeps money the way every other row does. */
    expect(Shipment::sole()->courier_charge)->toBe(Money::toMinorUnits($script->deliveryFee()));
});

it('renews pathao\'s access token before pathao\'s own expiry, with nobody pasting anything', function () {
    $script = new PathaoScript;

    /** Pathao says ten minutes. The token is let go of after five, while Pathao would still take it. */
    $script->fakeBookedWithTokenLasting(600);

    sendWithCourier(orderReadyToSend($script), $script)->assertNotified();

    $script->assertTokensIssued(1);

    $this->travel(301)->seconds();

    $later = orderReadyToSend($script);

    answeringAfresh();
    $script->fakeBookedWithTokenLasting(600);

    sendWithCourier($later, $script)->assertNotified();

    /**
     * Counted since the fake was laid afresh: a token was asked for again five
     * minutes into a ten-minute lifetime, which is the margin that keeps a
     * parcel from being refused because a token expired between the button and
     * the call.
     */
    $script->assertTokensIssued(1);
    $script->assertBookedFor(Shipment::all()->last());
});

it('says pathao cannot be used when it will not issue a token, and never that it refused the parcel', function () {
    $script = new PathaoScript;

    $order = orderReadyToSend($script);

    $script->fakeTokenRefused();

    sendWithCourier($order, $script);

    $notification = lastNotification();

    /** Nobody asked Pathao about a parcel, and the answer is a Merchant's to give in Settings. */
    expect(Shipment::count())->toBe(0)
        ->and($order->refresh()->isShipping())->toBeFalse()
        ->and($notification?->getTitle())->toBe('Pathao cannot be used')
        ->and($notification?->getBody())->toContain($script->tokenRefusalWording());

    $script->assertBookingsSent(0);
});

it('lets go of a token pathao has stopped recognising, so the next parcel goes under a fresh one', function () {
    $script = new PathaoScript;

    $order = orderReadyToSend($script);

    $script->fakeTokenRejected();

    sendWithCourier($order, $script);

    /** Pathao never looked at the parcel, so nothing says it refused one: it declined the word the call was signed with. */
    expect(lastNotification()?->getTitle())->toBe('Pathao cannot be used')
        ->and(Shipment::count())->toBe(0);

    $script->assertTokensIssued(1);

    answeringAfresh();
    $script->fakeBooked();

    sendWithCourier($order->refresh(), $script)->assertNotified();

    /** A held token Pathao had already disowned would have refused this one too. */
    $script->assertTokensIssued(1);
    $script->assertBookedFor(Shipment::sole());
});
