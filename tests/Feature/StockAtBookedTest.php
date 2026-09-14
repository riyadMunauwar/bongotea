<?php

use App\Enums\AdjustmentCause;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * Stock leaves when the parcel leaves.
 *
 * Every case here drives the real thing — the send dialog a User presses, a
 * morning's Orders batched and handed over by the sweep, a Courier asked
 * where a parcel is, a Courier announcing it on its own — and then asks the
 * ledger what the shelf holds. Nothing calls `StockLedger` and reads back
 * what it just wrote, because that would prove only that a method returns
 * what it was given; what is under test is that a box leaving the building
 * moves a figure a Merchant reads (ADR-0021).
 *
 * Every case runs once per Courier over `tests/Datasets/CourierScripts.php`,
 * for the reason the courier suites do: a parcel leaves the same way whoever
 * carries it, and a Courier added later is a script and a dataset row rather
 * than a file.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->ledger = app(StockLedger::class);
});

/**
 * An Order of one Product somebody has counted a shelf of, ready to be sent
 * with this Courier: so many units held, so many of them ordered.
 *
 * @return array{Order, Product}
 */
function parcelOfCountedStock(CourierScript $script, int $held = 10, int $ordered = 3): array
{
    $order = orderReadyToSend($script);

    $order->lines()->sole()->update(['quantity' => $ordered]);

    $product = $order->lines()->sole()->product;

    app(StockLedger::class)->recordCount($product, $held, AdjustmentCause::OpeningCount);

    return [$order->refresh(), $product];
}

/**
 * What the ledger wrote for the parcels, and only that: the count that put
 * the Product on the shelf in the first place is not one of these.
 *
 * @return Builder<StockMovement>
 */
function saleMovements(): Builder
{
    return StockMovement::query()->where('reason', StockMovementReason::Sale);
}

/*
|--------------------------------------------------------------------------
| A courier takes the parcel
|--------------------------------------------------------------------------
*/

it('takes the units out of the ledger when a courier takes the parcel', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $script->fakeBooked();

    sendWithCourier($order, $script)->assertHasNoActionErrors();

    expect($this->ledger->onHand($product))->toBe(7);

    $movement = saleMovements()->sole();

    /** Out, caused by the parcel itself — which is what makes a second telling of the same booking harmless. */
    expect($movement->quantity)->toBe(-3)
        ->and($movement->source->is(Shipment::sole()))->toBeTrue()

        /**
         * Nobody's hand. A User pressed the button, but what moved the units
         * was a Courier taking the box — and the same movement is written by
         * a sweep at four in the morning with nobody signed in at all.
         */
        ->and($movement->user_id)->toBeNull();
})->with('courier scripts');

it('stamps what a unit was worth as it left, so the shelf loses exactly what it was carrying', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    /** Bought at 400.00 each, which is what puts this Product in the ledger and what gives it an average at all. */
    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 5, 'unit_cost' => 40000],
    ]);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    $movement = saleMovements()->sole();

    /** The average in force at the moment it moved, stamped on the row and never recomputed (ADR-0022). */
    expect($movement->unit_cost)->toBe(40000)
        ->and($this->ledger->onHand($product))->toBe(4)
        ->and($this->ledger->valueAtCost($product))->toBe(160000);
})->with('courier scripts');

it('moves nothing for a product nobody has counted', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    $script->fakeBooked();

    sendWithCourier($order, $script)->assertHasNoActionErrors();

    /** A Product joins the ledger when somebody counts it and not before, which is what makes adoption five Products at a time (ADR-0021). */
    expect(StockMovement::count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBeNull()
        ->and(Shipment::sole()->status)->toBe(ShipmentStatus::Booked);
})->with('courier scripts');

it('moves nothing when the courier refuses the parcel, because it never left', function (CourierScript $script) {
    [, $product] = parcelOfCountedStock($script);

    $script->fakeBookingRefused();

    sendWithCourier(Order::sole(), $script);

    /** A refused booking records no attempt at all (ADR-0020), so there is nothing for the ledger to be asked about. */
    expect(Shipment::count())->toBe(0)
        ->and(saleMovements()->count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBe(10);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| A batch: written first, handed over after
|--------------------------------------------------------------------------
*/

it('moves nothing for a parcel written down and handed to nobody yet', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    sendBatchWithCourier([$order], $script)->assertHasNoActionErrors();

    /** Awaiting Dispatch: the row exists and the box is still on the shelf, because nobody has been handed anything. */
    expect(Shipment::sole()->status)->toBe(ShipmentStatus::Written)
        ->and(saleMovements()->count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBe(10);
})->with('courier scripts');

it('takes the units when the sweep hands a written parcel over', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    sendBatchWithCourier([$order], $script);

    answeringAfresh();
    $script->fakeBatchBooked();

    $this->artisan('couriers:sweep')->assertSuccessful();

    expect(Shipment::sole()->status)->toBe(ShipmentStatus::Booked)
        ->and(saleMovements()->count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(7);
})->with('courier scripts');

it('moves nothing for a parcel a courier refused out of a batch', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    sendBatchWithCourier([$order], $script);

    answeringAfresh();
    $script->fakeBatchRefused();

    $this->artisan('couriers:sweep')->assertSuccessful();

    $parcel = Shipment::sole();

    /** Refused at Booking leaves the row saying it was never dispatched, and a parcel that never went moves nothing. */
    expect($parcel->refused_at)->not->toBeNull()
        ->and($parcel->wasHandedOver())->toBeFalse()
        ->and(saleMovements()->count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBe(10);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| Told twice, moved once
|--------------------------------------------------------------------------
*/

it('takes the units once, however far the parcel then gets', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    [, $movingWord] = stillMoving($script);

    answeringAfresh();
    $script->fakeTracking($movingWord);

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();
    $this->artisan('couriers:sweep')->assertSuccessful();

    /** The parcel moved on and the shelf did not: the units left once, when the Courier took them. */
    expect(Shipment::sole()->status)->not->toBe(ShipmentStatus::Booked)
        ->and(saleMovements()->count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(7);
})->with('courier scripts');

it('takes the units once when a webhook and a poll report the same booking', function (CourierScript $script) {
    [$order, $product] = parcelOfCountedStock($script);

    $script->fakeBooked();

    sendWithCourier($order, $script);

    $parcel = Shipment::sole();
    $bookedWord = $script->rawStatusesFor(ShipmentStatus::Booked)[0];

    /** The Courier announces the booking it has already told us about, and our own call confirms it (ADR-0019). */
    answeringAfresh();
    $script->fakeTracking($bookedWord);

    $this->post(announcementUrl($script), $script->statusAnnouncement($parcel->invoice_reference, $bookedWord))->assertOk();

    /** And the sweep asks about the same parcel again, which is ordinary traffic rather than an edge case. */
    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();
    $this->artisan('couriers:sweep')->assertSuccessful();

    expect(saleMovements()->count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(7);
})->with('announcing couriers');
