<?php

use App\Enums\AdjustmentCause;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\StockMovementReason;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\CommittedStock;
use App\Support\Inventory\StockLedger;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * A retail Order moves stock the way a bulk one does (ADR-0021, ADR-0035):
 * each Pack line leaves its Product's one shelf by its weight × its count, in
 * grams, when the parcel is handed over, and comes back by the same grams.
 *
 * Throughout: ten kilograms counted onto the shelf, and a parcel of four
 * 250 g Packs — one kilogram — sent with each Courier in turn.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->answeringPartlyDelivered()->create());

    $this->ledger = app(StockLedger::class);
});

/**
 * A retail Order of one Pack line, ready to send, its Product's shelf
 * counted at ten kilograms.
 *
 * @return array{Order, Product}
 */
function retailParcel(CourierScript $script, int $grams = 250, int $count = 4): array
{
    $order = orderReadyToSend($script, ['kind' => OrderKind::Retail]);
    $product = $order->lines()->sole()->product;
    $order->lines()->sole()->delete();

    $pack = Pack::factory()->for($product)->create(['grams' => $grams, 'mrp' => 30000, 'price' => 27000]);
    OrderLine::factory()->for($order)->ofPack($pack, $count)->create();

    app(StockLedger::class)->recordCount($product, 10000, AdjustmentCause::OpeningCount);

    return [$order->refresh(), $product];
}

it('takes each Pack line\'s weight × count off the shelf when the courier takes the parcel', function (CourierScript $script) {
    [$order, $product] = retailParcel($script);

    $script->fakeBooked();
    sendWithCourier($order, $script)->assertHasNoActionErrors();

    expect(parcelMovements(StockMovementReason::Sale)->sole()->quantity)->toBe(-1000)
        ->and($this->ledger->onHand($product))->toBe(9000);
})->with('courier scripts');

it('puts the Packs\' weight back when the courier brings the parcel back', function (CourierScript $script) {
    [$order, $product] = retailParcel($script);

    parcelReportedAs($order, $script, $script->returnedWording());

    expect(parcelMovements(StockMovementReason::Return)->sole()->quantity)->toBe(1000)
        ->and($this->ledger->onHand($product))->toBe(10000);
})->with('courier scripts');

it('answers a partly delivered parcel line by line, by the Packs\' weight', function (CourierScript $script) {
    [$order, $product] = retailParcel($script);

    parcelReportedAs($order, $script, $script->partlyDeliveredWording());

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('answerPartlyDelivered', ['order_line_ids' => [(string) $order->lines()->sole()->getKey()]])
        ->assertHasNoActionErrors();

    expect(parcelMovements(StockMovementReason::PartialReturn)->sole()->quantity)->toBe(1000)
        ->and($this->ledger->onHand($product))->toBe(10000);
})->with('courier scripts');

it('writes an abandoned parcel off by the Packs\' weight', function (CourierScript $script) {
    [$order, $product] = retailParcel($script);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    $this->actingAs(User::factory()->abandoningParcels()->create());

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('abandonShipment')
        ->assertHasNoActionErrors();

    expect(parcelMovements(StockMovementReason::Unsold)->sole()->quantity)->toBe(1000)
        ->and(parcelMovements(StockMovementReason::Loss)->sole()->quantity)->toBe(-1000)
        ->and($this->ledger->onHand($product))->toBe(9000);
})->with('courier scripts');

it('keeps On Hand and Value at Cost right across bulk and retail parcels off one shelf', function (CourierScript $script) {
    [$retail, $product] = retailParcel($script, grams: 500, count: 3);

    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 10000, 'unit_cost' => 40000],
    ]);

    $bulk = orderReadyToSend($script);
    $bulk->lines()->sole()->update(['product_id' => $product->getKey(), 'quantity' => 2]);

    $script->fakeBooked();
    sendWithCourier($retail, $script)->assertHasNoActionErrors();
    sendWithCourier($bulk->refresh(), $script)->assertHasNoActionErrors();

    /**
     * Twenty kilograms held — ten counted uncosted, ten bought at 400.00 —
     * so a 200.00 blend, and 1.5 kg of Packs and 2 kg loose gone at it
     * without moving it.
     */
    expect($this->ledger->onHand($product))->toBe(16500)
        ->and($this->ledger->averageCost($product))->toBe(20000)
        ->and($this->ledger->valueAtCost($product))->toBe(330000);
})->with('courier scripts');

it('counts a Confirmed retail Order nobody has sent as committed by its weight', function () {
    $pack = Pack::factory()->create(['grams' => 250]);
    $order = Order::factory()->retail()->create(['status' => OrderStatus::Confirmed]);
    OrderLine::factory()->for($order)->ofPack($pack, 3)->create();

    $product = app(CommittedStock::class)->withCommitted(Product::query()->whereKey($pack->product_id))->sole();

    expect((int) $product->{CommittedStock::COMMITTED})->toBe(750);
});

it('sends a retail parcel\'s weight in kilograms from its Packs, where the courier asks for one', function (CourierScript $script) {
    [$order] = retailParcel($script, grams: 250, count: 3);

    $script->fakeBooked();
    sendWithCourier($order, $script)->assertHasNoActionErrors();

    $asksWeight = collect($script->courier()->driver()->parcelFields())->contains(fn ($field): bool => $field->isWeight);

    expect(Shipment::sole()->parcel_details['parcelWeight'] ?? null)->toBe($asksWeight ? '0.75' : null);
})->with('courier scripts');
