<?php

use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * The two ways a parcel ends that are not a delivery.
 *
 * A parcel that comes back puts its units on the shelf again; one a User
 * gives up as dead is money written off. Both are answered at the cost the
 * units left at and never at what the shelf has cost since, which is the
 * whole of what stamping a movement is for (ADR-0022).
 *
 * Every case drives the real thing — a Courier asked where a parcel is, a
 * Courier announcing it, a User pressing "Abandon parcel" — and then asks the
 * ledger what the shelf holds, exactly as `StockAtBookedTest` does for the
 * leaving. Nothing calls `StockLedger` and reads back what it just wrote.
 *
 * Every case runs once per Courier over `tests/Datasets/CourierScripts.php`,
 * for the reason the courier suites do: a parcel comes back the same way
 * whoever was carrying it.
 */
beforeEach(function (): void {
    /** Abandoning a parcel needs `abandon parcels` on top of the `work orders` floor its screen sits behind. */
    $this->actingAs(User::factory()->abandoningParcels()->create());

    $this->ledger = app(StockLedger::class);
});

/*
|--------------------------------------------------------------------------
| The parcel comes back
|--------------------------------------------------------------------------
*/

it('puts the units back on the shelf when the courier brings the parcel back', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $parcel = parcelReportedAs($order, $script, $script->returnedWording());

    expect($parcel->status)->toBe(ShipmentStatus::Returned)
        ->and($this->ledger->onHand($product))->toBe(10000);

    $movement = parcelMovements(StockMovementReason::Return)->sole();

    /** In, caused by the parcel itself — the exact opposite of the row it answers. */
    expect($movement->quantity)->toBe(3000)
        ->and($movement->source->is($parcel))->toBeTrue()

        /** Nobody's hand: what brought the units back was a Courier, and the sweep that heard about it runs with nobody signed in. */
        ->and($movement->user_id)->toBeNull();
})->with('courier scripts');

it('brings the units back at what they left at, however the price has moved since', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    /** Bought at 400.00 each, which is the average in force when the parcel goes out. */
    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 5000, 'unit_cost' => 40000],
    ]);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    /** And a second delivery at 600.00 lands while the parcel is out, which moves the average for everything that leaves after it. */
    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 5000, 'unit_cost' => 60000],
    ]);

    answeringAfresh();
    $script->fakeTracking($script->returnedWording());

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();
    $this->artisan('couriers:sweep')->assertSuccessful();

    /** What came back is what went out: the figure stamped on the Sale, not the blend the shelf holds today (ADR-0022). */
    expect(parcelMovements(StockMovementReason::Return)->sole()->unit_cost)->toBe(40000)
        ->and($this->ledger->onHand($product))->toBe(10000)

        /** Five at 400.00 and five at 600.00, every one of them back on the shelf and none of them revalued by the round trip. */
        ->and($this->ledger->valueAtCost($product))->toBe(500000)
        ->and($this->ledger->averageCost($product))->toBe(50000);
})->with('courier scripts');

it('brings back units nobody had costed carrying no money at all', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    /** Counted but never bought, so nothing was known to stamp: the parcel leaves carrying no figure (ADR-0022). */
    expect(parcelMovements(StockMovementReason::Sale)->sole()->unit_cost)->toBeNull();

    /** And the first delivery anybody records lands while the parcel is out. */
    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 5000, 'unit_cost' => 40000],
    ]);

    answeringAfresh();
    $script->fakeTracking($script->returnedWording());

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();
    $this->artisan('couriers:sweep')->assertSuccessful();

    /**
     * Unknown comes back as unknown. Stamping the average in force instead
     * would walk 1,200.00 into the ledger for units nothing recorded paying
     * for, and the shelf would be worth more after a round trip than before
     * it.
     */
    expect(parcelMovements(StockMovementReason::Return)->sole()->unit_cost)->toBeNull()
        ->and($this->ledger->onHand($product))->toBe(15000)
        ->and($this->ledger->valueAtCost($product))->toBe(200000);
})->with('courier scripts');

it('brings the units back once, however many times the courier says the parcel came back', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $parcel = parcelReportedAs($order, $script, $script->returnedWording());

    /** The Courier announces what our own call already told us, which is ordinary traffic rather than an edge case (ADR-0019). */
    answeringAfresh();
    $this->post(announcementUrl($script), $script->statusAnnouncement($parcel->invoice_reference, $script->returnedWording()))->assertOk();

    expect(parcelMovements(StockMovementReason::Return)->count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(10000);
})->with('announcing couriers');

it('brings nothing back for a product nobody has counted', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    $parcel = parcelReportedAs($order, $script, $script->returnedWording());

    /** Nothing left a shelf it was never on, so nothing comes back to one (ADR-0021). */
    expect($parcel->status)->toBe(ShipmentStatus::Returned)
        ->and(StockMovement::count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBeNull();
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| The parcel is given up as dead
|--------------------------------------------------------------------------
*/

it('writes the parcel off as a loss at what its units cost when they left', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 10000, 'unit_cost' => 40000],
    ]);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('abandonShipment')
        ->assertHasNoActionErrors();

    $loss = parcelMovements(StockMovementReason::Loss)->sole();

    expect($loss->quantity)->toBe(-1000)
        ->and($loss->unit_cost)->toBe(40000)
        ->and($loss->source->is(Shipment::sole()))->toBeTrue()

        /**
         * The units left the shelf once, when the Courier took the box. The
         * write-off names what that cost and must not take the same box a
         * second time, so the parcel is taken back off the sale at what it
         * left at and written off from there — and nothing pretends a box
         * came back through the door.
         */
        ->and(parcelMovements(StockMovementReason::Unsold)->sole()->quantity)->toBe(1000)
        ->and(parcelMovements(StockMovementReason::Return)->count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBe(9000)
        ->and($this->ledger->valueAtCost($product))->toBe(360000)
        ->and($this->ledger->averageCost($product))->toBe(40000);
})->with('courier scripts');

it('leaves the courier\'s last word standing over a parcel it has written off', function (CourierScript $script) {
    [$order] = parcelOfCountedStock($script);

    [$movingStatus, $movingWord] = stillMoving($script);

    $parcel = parcelReportedAs($order, $script, $movingWord);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('abandonShipment')
        ->assertHasNoActionErrors();

    /** Abandoned is a time and a name and never a status (ADR-0018) — and the ledger hears about it all the same. */
    expect($parcel->refresh()->status)->toBe($movingStatus)
        ->and($parcel->abandoned_at)->not->toBeNull()
        ->and(parcelMovements(StockMovementReason::Loss)->sole()->quantity)->toBe(-3000);
})->with('courier scripts');

it('writes nothing off for a parcel nobody has been handed', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    sendBatchWithCourier([$order], $script)->assertHasNoActionErrors();

    $parcel = Shipment::sole();

    expect($parcel->isAwaitingDispatch())->toBeTrue();

    /**
     * The screen refuses this and so does `Shipment::abandon()`, so the ledger
     * is asked the question the only way anything could ask it: by the column
     * changing underneath it. A parcel nobody has been handed has taken
     * nothing off a shelf, so there is nothing to write off.
     */
    $parcel->forceFill(['abandoned_at' => now()])->save();

    expect(StockMovement::where('reason', '!=', StockMovementReason::Adjustment)->count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBe(10000);
})->with('courier scripts');

it('writes nothing off twice, however often a parcel is written off', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    $parcel = Shipment::sole();

    $parcel->abandon(User::factory()->create());

    /**
     * `Shipment::abandon()` leaves an attempt already written off exactly as
     * it was, so the second telling is written straight at the column — which
     * is also what a later save touching it looks like to the observer.
     */
    $parcel->refresh()->forceFill(['abandoned_at' => now()])->save();

    expect(parcelMovements(StockMovementReason::Loss)->count())->toBe(1)
        ->and(parcelMovements(StockMovementReason::Unsold)->count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(7000);
})->with('courier scripts');

it('puts the units back when the courier brings back a parcel already given up on', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('abandonShipment')
        ->assertHasNoActionErrors();

    expect($this->ledger->onHand($product))->toBe(7000);

    /**
     * And then the Courier announces it, weeks after a User gave up waiting.
     * The announcement is what reaches a written-off parcel at all: the sweep
     * asks only about attempts still worth waiting on, where an Announcement
     * is refused for a parcel nobody has been handed and for nothing else.
     */
    answeringAfresh();
    $script->fakeTracking($script->returnedWording());

    $this->post(announcementUrl($script), $script->statusAnnouncement(Shipment::sole()->invoice_reference, $script->returnedWording()))->assertOk();

    /**
     * The box is on the shelf, so the shelf says so. The write-off used its
     * own word for taking the units off the sale, which is what leaves the
     * Return free to be written — one reason to a Shipment is the ledger's
     * rule, and a Return already spent on the write-off would have been
     * swallowed as a second telling.
     */
    expect(Shipment::sole()->status)->toBe(ShipmentStatus::Returned)
        ->and(parcelMovements(StockMovementReason::Return)->sole()->quantity)->toBe(3000)
        ->and($this->ledger->onHand($product))->toBe(10000)

        /** What a User believed at the time stays on the record: the loss was written and the parcel then came back. */
        ->and(parcelMovements(StockMovementReason::Loss)->count())->toBe(1);
})->with('announcing couriers');

it('writes nothing off for a parcel the courier already brought back', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $parcel = parcelReportedAs($order, $script, $script->returnedWording());

    /** The Courier has finished with it and the units are on the shelf: what became of them is already on the record. */
    $parcel->forceFill(['abandoned_at' => now()])->save();

    expect(parcelMovements(StockMovementReason::Loss)->count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBe(10000);
})->with('courier scripts');
