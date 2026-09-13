<?php

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Settings\CourierSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * A morning's Orders out in one action: the batch written down while a User
 * watches, and dispatched by the sweep with nobody watching at all
 * (ADR-0020).
 *
 * Every case runs once per Courier over `tests/Datasets/CourierScripts.php`.
 * They disagree here as sharply as they do about tracking — one takes five
 * hundred parcels in a single call and two take one each — and every one of
 * those differences is asked of the script, never branched on by name: a test
 * asks `batchLimit()` and `batchSizes()`, and a Courier that grows a bulk
 * endpoint moves between the shapes on the strength of one declaration.
 *
 * What is under test is the whole path and not either half: rows written
 * before anything is dispatched is the only thing that makes a batch
 * interrupted halfway recoverable, and it is worth nothing unless the sweep
 * then offers each row exactly once.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * This many Orders, each ready to be sent with this Courier and none of them
 * sent.
 *
 * @return list<Order>
 */
function ordersReadyToSend(CourierScript $script, int $count): array
{
    return array_map(fn (int $each): Order => orderReadyToSend($script), range(1, $count));
}

/**
 * Tick these Orders on the queue and press "Send with courier", choosing this
 * Courier once for the lot — which is the only way this feature is ever
 * reached: the selection is the set, and the selection is what the screen
 * shows.
 *
 * @param  list<Order>  $orders
 */
function sendBatchWithCourier(array $orders, CourierScript $script, ?string $courier = null): Testable
{
    return Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->selectTableRecords(array_map(fn (Order $order): int => $order->getKey(), $orders))
        ->callAction(
            TestAction::make('bookBatchWithCourier')->table()->bulk(),
            data: ['courier' => $courier ?? $script->courier()->value],
        );
}

/**
 * The attempt written down for this Order, whatever state it has reached
 * since.
 */
function parcelFor(Order $order): Shipment
{
    return Shipment::query()->where('order_id', $order->getKey())->sole();
}

/*
|--------------------------------------------------------------------------
| Written first, dispatched after
|--------------------------------------------------------------------------
*/

it('writes a row for every order in the batch and asks no courier anything yet', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 3);

    sendBatchWithCourier($orders, $script);

    expect(Shipment::query()->count())->toBe(3);

    foreach ($orders as $order) {
        $parcel = parcelFor($order);

        /** Written and not handed over: no Consignment, no word of the Courier's, no moment the attempt began. */
        expect($parcel->status)->toBe(ShipmentStatus::Written)
            ->and($parcel->consignment_reference)->toBeNull()
            ->and($parcel->raw_status)->toBeNull()
            ->and($parcel->booked_at)->toBeNull()
            ->and($parcel->courier)->toBe($script->courier())

            /** Active from the moment it was written, which is what stops the same Order going into a second batch. */
            ->and($parcel->isActive())->toBeTrue()
            ->and($order->refresh()->isShipping())->toBeTrue();
    }

    /** Nothing was dispatched while the User waited — that is the whole of why a batch is not a single booking. */
    expect($script->batchSizes())->toBe([]);
})->with('courier scripts');

it('dispatches the written rows on the next sweep, each with the consignment its own answer named', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 3);

    sendBatchWithCourier($orders, $script);

    answeringAfresh();
    $script->fakeBatchBooked();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 3 parcels')
        ->assertSuccessful();

    foreach ($orders as $order) {
        $parcel = parcelFor($order)->refresh();

        expect($parcel->status)->toBe(ShipmentStatus::Booked)
            ->and($parcel->booked_at)->not->toBeNull()

            /** Matched back by our own invoice reference, which is the one name both sides of the call agree on. */
            ->and($parcel->consignment_reference)->toBe($script->consignmentFor($parcel->invoice_reference))

            /** The first thing that happened to the parcel is on its timeline. */
            ->and($parcel->events()->count())->toBe(1);
    }

    /** One call for a Courier that takes a batch, one per parcel for a Courier that does not. */
    expect($script->batchSizes())->toHaveCount($script->bookingCallsFor(3));
})->with('courier scripts');

it('shows a written row on the orders queue as awaiting dispatch, and as booked once it has gone', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 1);

    sendBatchWithCourier($orders, $script);

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->assertSee(ShipmentStatus::Written->getLabel());

    answeringAfresh();
    $script->fakeBatchBooked();

    $this->artisan('couriers:sweep')->assertSuccessful();

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->assertSee(ShipmentStatus::Booked->getLabel())
        ->assertDontSee(ShipmentStatus::Written->getLabel());
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| How many parcels one call carries
|--------------------------------------------------------------------------
*/

it('cuts the batch at the couriers own limit and sends one call per batch', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $limit = $script->batchLimit();
    $parcels = $limit + 1;

    /**
     * Written straight in rather than through the action: what is under test
     * is the sweep's cut, and five hundred real Orders would prove the same
     * thing far more slowly.
     */
    $rows = [];

    for ($each = 1; $each <= $parcels; $each++) {
        $rows[] = [
            'order_id' => $order->getKey(),
            'courier' => $script->courier()->value,
            'status' => ShipmentStatus::Written->value,
            'invoice_reference' => "FILLER-{$each}",
            'collect_amount' => 105000,
            'recipient_name' => 'Rahim Uddin',
            'recipient_phone' => '+8801712345678',
            'recipient_address' => 'House 4, Road 12, Banani',
            'parcel_details' => json_encode(parcelDetailsForBatch($script)),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('shipments')->insert($rows);

    answeringAfresh();
    $script->fakeBatchBooked();

    $this->artisan('couriers:sweep')->assertSuccessful();

    /** The limit itself, not a proxy for it: a full call and then the one that was left over. */
    expect($script->batchSizes())->toBe($limit === 1 ? [1, 1] : [$limit, 1])
        ->and(Shipment::query()->where('status', ShipmentStatus::Booked)->count())->toBe($parcels);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| What a batch refuses to do
|--------------------------------------------------------------------------
*/

it('never books an order a parcel is already out for, and says so before it commits', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 2);

    /** One of them already has a parcel out, which is the one thing a batch must never send again. */
    parcelInFlight($script, ['order_id' => $orders[0]->getKey()]);

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->selectTableRecords(array_map(fn (Order $order): int => $order->getKey(), $orders))
        ->mountAction(TestAction::make('bookBatchWithCourier')->table()->bulk())

        /** Said before the User commits, because the set dispatched has to be the set they were looking at. */
        ->assertMountedActionModalSee('1 parcel will be booked')
        ->assertMountedActionModalSee('1 order will be skipped');

    sendBatchWithCourier($orders, $script);

    /** One written, and the parcel already out is untouched: still one attempt on that Order and still the one it had. */
    expect(Shipment::query()->count())->toBe(2)
        ->and(Shipment::query()->where('order_id', $orders[0]->getKey())->count())->toBe(1)
        ->and(parcelFor($orders[1])->status)->toBe(ShipmentStatus::Written);
})->with('courier scripts');

it('never books an order whose parcel already arrived, and names it back rather than counting it', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 2);

    /** A User ticking a week of the queue ticks delivered Orders, and a second parcel for one is a parcel nobody ordered. */
    Shipment::factory()->for($orders[0])->delivered()->create(['courier' => $script->courier()]);

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->selectTableRecords(array_map(fn (Order $order): int => $order->getKey(), $orders))
        ->mountAction(TestAction::make('bookBatchWithCourier')->table()->bulk())
        ->assertMountedActionModalSee('1 parcel will be booked')
        ->assertMountedActionModalSee('1 order will be skipped');

    sendBatchWithCourier($orders, $script);

    expect(Shipment::query()->where('order_id', $orders[0]->getKey())->count())->toBe(1)
        ->and(parcelFor($orders[1])->status)->toBe(ShipmentStatus::Written)
        ->and(lastNotification()?->getBody())->toContain("Already delivered, so not sent again: {$orders[0]->order_number}");
})->with('courier scripts');

it('writes nothing twice when the same batch is sent again', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 2);

    sendBatchWithCourier($orders, $script);
    sendBatchWithCourier($orders, $script);

    /** The row written by the first batch is Active, so the second one passes both Orders over. */
    expect(Shipment::query()->count())->toBe(2);
})->with('courier scripts');

it('leaves an order needing a delivery area unsent and names it back, rather than guessing a depot', function (CourierScript $script) {
    $sendable = orderReadyToSend($script);

    /** An address this Courier's own list makes nothing of, and no District to fall back on. */
    $unplaceable = orderReadyToSend($script, [
        'customer_address' => 'Behind the big tree',
        'customer_district' => null,
    ]);

    sendBatchWithCourier([$sendable, $unplaceable], $script);

    expect(Shipment::query()->count())->toBe(1)
        ->and(parcelFor($sendable)->status)->toBe(ShipmentStatus::Written)
        ->and($unplaceable->refresh()->isShipping())->toBeFalse()

        /** Named rather than counted: a User has to open that Order and send it themselves. */
        ->and(lastNotification()?->getBody())->toContain($unplaceable->order_number);
})->with('area-naming couriers');

it('books an order whose address names nowhere in particular, where the courier reads free text', function (CourierScript $script) {
    $order = orderReadyToSend($script, [
        'customer_address' => 'Behind the big tree',
        'customer_district' => null,
    ]);

    sendBatchWithCourier([$order], $script);

    /** Nothing to resolve, so nothing to fail to resolve: the Courier itself decides where this goes. */
    expect(parcelFor($order)->status)->toBe(ShipmentStatus::Written);
})->with('free-text couriers');

/*
|--------------------------------------------------------------------------
| A batch that partly failed
|--------------------------------------------------------------------------
*/

it('records which parcels the courier refused and why, and leaves the rest alone', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 3);

    sendBatchWithCourier($orders, $script);

    $taken = parcelFor($orders[0])->invoice_reference;

    answeringAfresh();
    $script->fakeBatchPartlyBooked([$taken]);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 1 parcel')
        ->expectsOutputToContain('Rejected 2 parcels at booking')
        ->assertSuccessful();

    expect(parcelFor($orders[0])->refresh()->status)->toBe(ShipmentStatus::Booked);

    foreach ([$orders[1], $orders[2]] as $refused) {
        $parcel = parcelFor($refused)->refresh();

        /** The row still says truthfully that it was never dispatched; the refusal is a time and a reason beside it. */
        expect($parcel->status)->toBe(ShipmentStatus::Written)
            ->and($parcel->consignment_reference)->toBeNull()
            ->and($parcel->refused_at)->not->toBeNull()
            ->and($parcel->refusal_reason)->toContain($script->refusalWording())

            /** And it is out of Active, so the Order is one to send again. */
            ->and($parcel->isActive())->toBeFalse()
            ->and($refused->refresh()->isShipping())->toBeFalse();
    }
})->with('courier scripts');

it('writes down the parcels a batch placed before it was cut short, and never books them again', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 3);

    sendBatchWithCourier($orders, $script);

    /** The one the sweep offers first, because a Courier sent one parcel a call gets no further than the first failure. */
    $first = Shipment::query()->orderBy('id')->firstOrFail();

    answeringAfresh();

    /** The Courier takes that parcel and then settles nothing else — the failure the written rows exist for (ADR-0020). */
    $script->fakeBatchCutShortAfter([$first->invoice_reference]);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 1 parcel')
        ->assertSuccessful();

    /** The parcel it took is real, so it is recorded as taken whatever happened next. */
    expect($first->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($first->consignment_reference)->toBe($script->consignmentFor($first->invoice_reference));

    /** And the two it never answered about are neither booked nor refused: they simply wait. */
    foreach (Shipment::query()->whereKeyNot($first->getKey())->get() as $waiting) {
        expect($waiting->status)->toBe(ShipmentStatus::Written)
            ->and($waiting->refused_at)->toBeNull();
    }

    answeringAfresh();
    $script->fakeBatchBooked();
    $script->fakeTracking($script->rawStatusesFor(ShipmentStatus::Booked)[0]);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 2 parcels')
        ->assertSuccessful();

    /** Three Orders, three parcels, and the one already placed was never offered a second time. */
    expect(Shipment::query()->where('status', ShipmentStatus::Booked)->count())->toBe(3)
        ->and(Shipment::query()->count())->toBe(3)
        ->and($script->batchSizes())->toHaveCount($script->bookingCallsFor(2));
})->with('courier scripts');

it('frees every order to be sent again when the courier refuses the whole batch', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 2);

    sendBatchWithCourier($orders, $script);

    answeringAfresh();
    $script->fakeBatchRefused();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 0 parcels')
        ->expectsOutputToContain('Rejected 2 parcels at booking')
        ->assertSuccessful();

    foreach ($orders as $order) {
        expect(parcelFor($order)->refresh()->refusal_reason)->toContain($script->refusalWording())
            ->and($order->refresh()->isShipping())->toBeFalse()

            /** Nothing of this Order's is Active or ever reached a door, so the queue offers it again. */
            ->and(Order::query()->notYetSent()->whereKey($order->getKey())->exists())->toBeTrue();
    }
})->with('courier scripts');

it('never offers a parcel the courier has already settled, however often the sweep runs', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 2);

    sendBatchWithCourier($orders, $script);

    $taken = parcelFor($orders[0])->invoice_reference;

    answeringAfresh();
    $script->fakeBatchPartlyBooked([$taken]);

    $this->artisan('couriers:sweep')->assertSuccessful();

    expect($script->batchSizes())->toHaveCount($script->bookingCallsFor(2));

    /**
     * The parcel it took is due to be asked about by the next sweep, which is
     * a different call and not a second offer. Laying this clears what has
     * been recorded so far, which is what makes the count below "since the
     * first sweep finished".
     */
    $script->fakeTracking($script->rawStatusesFor(ShipmentStatus::Booked)[0]);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 0 parcels')
        ->assertSuccessful();

    /** Neither the one it took nor the one it refused is offered again: both are settled on their own row. */
    expect($script->batchSizes())->toBe([])
        ->and(Shipment::query()->count())->toBe(2);
})->with('courier scripts');

it('leaves every written row alone when the courier cannot be reached, and books each exactly once after', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 2);

    sendBatchWithCourier($orders, $script);

    answeringAfresh();
    $script->fakeOutage();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 0 parcels')
        ->assertSuccessful();

    foreach ($orders as $order) {
        expect(parcelFor($order)->refresh()->status)->toBe(ShipmentStatus::Written)
            ->and(parcelFor($order)->refused_at)->toBeNull();
    }

    answeringAfresh();
    $script->fakeBatchBooked();

    $this->artisan('couriers:sweep')->assertSuccessful();

    /** One attempt each, under the reference each was written with: nothing was booked twice. */
    expect(Shipment::query()->where('status', ShipmentStatus::Booked)->count())->toBe(2)
        ->and(Shipment::query()->count())->toBe(2);
})->with('courier scripts');

it('passes a courier this installation cannot speak to over whole, and its written parcels wait', function (CourierScript $script) {
    $orders = ordersReadyToSend($script, 1);

    sendBatchWithCourier($orders, $script);

    /** Switched off after the batch was written, which is exactly the case a written row survives. */
    switchOffEveryCourier();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Handed over 0 parcels')
        ->assertSuccessful();

    expect(parcelFor($orders[0])->refresh()->status)->toBe(ShipmentStatus::Written)
        ->and($script->batchSizes())->toBe([]);
})->with('courier scripts');

/**
 * Everything this Courier is told about a parcel that a filler row must carry
 * for the driver to be able to send it, which for one Courier includes the
 * delivery area it will not take a parcel without.
 *
 * @return array<string, string>
 */
function parcelDetailsForBatch(CourierScript $script): array
{
    $details = $script->parcelRowFor($script->parcelDefaults());

    return array_map(fn (mixed $value): string => (string) $value, $details);
}

/** Every Courier switched off, so the sweep passes each of them over before reading a parcel of theirs. */
function switchOffEveryCourier(): void
{
    $off = [];

    foreach (Courier::cases() as $courier) {
        $off[CourierSettings::property($courier, 'Enabled')] = false;
    }

    app(CourierSettings::class)->with($off)->save();

    app()->forgetInstance(CourierSettings::class);
}
