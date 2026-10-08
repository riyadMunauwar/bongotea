<?php

use App\Enums\AdjustmentCause;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\ParcelNotAwaitingAnswer;
use App\Support\Inventory\StockLedger;
use App\Support\Reporting\AttentionReason;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * The one ending the ledger cannot work out for itself.
 *
 * A Courier reports a collected amount and never which items came back, so a
 * Partly Delivered parcel writes nothing on its own and becomes a question a
 * User answers (ADR-0021). Until they answer it the units are neither
 * returned nor lost, which is exactly what they are.
 *
 * Every case drives the real thing — a Courier reporting its own word for a
 * partial delivery, a User ticking the lines that came back on the Order's
 * own screen — and then asks the ledger what the shelf holds, as the other
 * two parcel suites do. Nothing calls `StockLedger` and reads back what it
 * just wrote, except where what is being proved is a refusal.
 *
 * Every case runs once per Courier over `tests/Datasets/CourierScripts.php`:
 * all three report a partial delivery in their own words, and none of them
 * says which items came back.
 */
beforeEach(function (): void {
    /** Answering a half-arrived parcel needs `answer partly delivered` on top of the `work orders` floor its screen sits behind. */
    $this->answering = User::factory()->answeringPartlyDelivered()->create();

    $this->actingAs($this->answering);

    $this->ledger = app(StockLedger::class);
});

/**
 * An Order of two counted Products sent with this Courier and reported back
 * as partly delivered: three of one and two of the other went out, and the
 * Courier has said only that some of it arrived.
 *
 * @return array{Order, Shipment, Product, Product}
 */
function parcelPartlyDelivered(CourierScript $script, int $held = 10): array
{
    [$order, $kept] = parcelOfCountedStock($script, $held, ordered: 3);

    $refused = Product::factory()->create(['name' => 'Wool Scarf', 'price' => 40000]);

    OrderLine::factory()->for($order)->for($refused)->create([
        'product_name' => 'Wool Scarf',
        'unit_price' => 40000,
        'quantity' => 2,
    ]);

    app(StockLedger::class)->recordCount($refused, $held, AdjustmentCause::OpeningCount);

    $parcel = parcelReportedAs($order->refresh(), $script, $script->partlyDeliveredWording());

    return [$order->refresh(), $parcel, $kept, $refused];
}

/**
 * The line of an Order that carries one Product, which is what a User ticks.
 */
function lineFor(Order $order, Product $product): OrderLine
{
    return $order->lines()->where('product_id', $product->getKey())->sole();
}

/**
 * What the whole installation is waiting on somebody for, by condition.
 *
 * Asked of the module rather than of a screen: what the list *says* is
 * `ReportingNeedsAttentionTest`'s and `DashboardCourierToDoTest`'s, and what
 * is asserted here is only that answering a parcel is what takes its Order
 * off the list.
 *
 * @return list<AttentionReason>
 */
function attentionReasons(): array
{
    return array_map(
        fn (NeedsAttentionRow $row): AttentionReason => $row->reason,
        app(ReportingQuery::class)->needsAttention()->rows,
    );
}

/**
 * Answer the question the way a User does: open the Order and tick the lines
 * that came back.
 *
 * @param  list<OrderLine>  $cameBack
 */
function sayWhatCameBack(Order $order, array $cameBack): void
{
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('answerPartlyDelivered', [
            'order_line_ids' => array_map(fn (OrderLine $line): string => (string) $line->getKey(), $cameBack),
        ])
        ->assertHasNoActionErrors();
}

/*
|--------------------------------------------------------------------------
| The parcel arrives in pieces
|--------------------------------------------------------------------------
*/

it('writes nothing of its own when the courier says the parcel was partly delivered', function (CourierScript $script) {
    [, $parcel, $kept, $refused] = parcelPartlyDelivered($script);

    expect($parcel->status)->toBe(ShipmentStatus::PartlyDelivered)

        /**
         * The Courier said an amount and never a list of items, so nothing
         * here may say which units came back — not as a Return, not as a
         * Loss, and not as a guess split between them (ADR-0021).
         */
        ->and(parcelMovements(StockMovementReason::Return)->count())->toBe(0)
        ->and(parcelMovements(StockMovementReason::PartialReturn)->count())->toBe(0)
        ->and(parcelMovements(StockMovementReason::Loss)->count())->toBe(0)
        ->and(parcelMovements(StockMovementReason::Unsold)->count())->toBe(0)

        /** The units left on the parcel and nothing has brought them back: neither returned nor lost. */
        ->and($this->ledger->onHand($kept))->toBe(7)
        ->and($this->ledger->onHand($refused))->toBe(8)

        /** And the question is open, which is what puts the Order in front of a User. */
        ->and($parcel->answered_at)->toBeNull();
})->with('courier scripts');

it('puts back the lines a User says came back, and nothing for the rest', function (CourierScript $script) {
    [$order, $parcel, $kept, $refused] = parcelPartlyDelivered($script);

    /** The question is somebody's to answer, so the Order is in front of them. */
    expect(attentionReasons())->toBe([AttentionReason::ParcelPartlyDelivered]);

    sayWhatCameBack($order, [lineFor($order, $refused)]);

    /** And answering is the only thing that takes it off again. */
    expect(attentionReasons())->toBe([]);

    $movement = parcelMovements(StockMovementReason::PartialReturn)->sole();

    expect($movement->quantity)->toBe(2)
        ->and($movement->product->is($refused))->toBeTrue()
        ->and($movement->source->is($parcel))->toBeTrue()

        /**
         * A name, unlike every other movement a parcel writes. What moved
         * these units was a Courier, but what says they moved is a person
         * answering a question nothing else could answer — and a surprising
         * answer has somebody to ask about it.
         */
        ->and($movement->user_id)->toBe($this->answering->getKey())

        /** Two of them back on the shelf, and the three the Customer kept still gone. */
        ->and($this->ledger->onHand($refused))->toBe(10)
        ->and($this->ledger->onHand($kept))->toBe(7)

        /** Answered, so the question is closed and whoever closed it is on the record. */
        ->and($parcel->refresh()->answered_at)->not->toBeNull()
        ->and($parcel->answered_by)->toBe($this->answering->getKey());
})->with('courier scripts');

it('brings the units back at what they left at, however the price has moved since', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    /** Bought at 400.00 each, which is the average in force when the parcel goes out. */
    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 5, 'unit_cost' => 40000],
    ]);

    $script->fakeBooked();
    sendWithCourier($order, $script);

    /** And a second delivery at 600.00 lands while the parcel is out. */
    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 5, 'unit_cost' => 60000],
    ]);

    answeringAfresh();
    $script->fakeTracking($script->partlyDeliveredWording());

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();
    $this->artisan('couriers:sweep')->assertSuccessful();

    sayWhatCameBack($order, [$order->lines()->sole()]);

    /** What came back is what went out: the figure stamped on the Sale, not the blend the shelf holds today (ADR-0022). */
    expect(parcelMovements(StockMovementReason::PartialReturn)->sole()->unit_cost)->toBe(40000)
        ->and($this->ledger->onHand($product))->toBe(10)
        ->and($this->ledger->valueAtCost($product))->toBe(500000)
        ->and($this->ledger->averageCost($product))->toBe(50000);
})->with('courier scripts');

it('closes the question with nothing where nothing came back', function (CourierScript $script) {
    [$order, $parcel, $kept, $refused] = parcelPartlyDelivered($script);

    /**
     * A User who rings the Customer and learns that everything stayed with
     * them answers the question with nothing. The parcel is settled and the
     * ledger asserts no movement, which is the honest reading of it.
     */
    sayWhatCameBack($order, []);

    expect(parcelMovements(StockMovementReason::PartialReturn)->count())->toBe(0)
        ->and($this->ledger->onHand($kept))->toBe(7)
        ->and($this->ledger->onHand($refused))->toBe(8)
        ->and($parcel->refresh()->answered_at)->not->toBeNull();
})->with('courier scripts');

it('brings nothing back for a product nobody has counted, and still closes the question', function (CourierScript $script) {
    $order = orderReadyToSend($script);
    $product = $order->lines()->sole()->product;

    $parcel = parcelReportedAs($order, $script, $script->partlyDeliveredWording());

    sayWhatCameBack($order, [$order->lines()->sole()]);

    /** Nothing left a shelf it was never on, so nothing comes back to one (ADR-0021) — and the question is still answered. */
    expect(StockMovement::count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBeNull()
        ->and($parcel->refresh()->answered_at)->not->toBeNull();
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| Answered once, and only where there is a question
|--------------------------------------------------------------------------
*/

it('answers the same parcel once, whoever asks and however often', function (CourierScript $script) {
    [$order, $parcel, , $refused] = parcelPartlyDelivered($script);

    sayWhatCameBack($order, [lineFor($order, $refused)]);

    /** The screen stops offering a question that has been answered. */
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('answerPartlyDelivered');

    /** And the ledger refuses it under the parcel's own lock, which is what answers the colleague whose page was drawn a minute ago. */
    expect(fn () => $this->ledger->recordPartialReturn($parcel->refresh(), [lineFor($order, $refused)->getKey()], $this->answering))
        ->toThrow(ParcelNotAwaitingAnswer::class)
        ->and(parcelMovements(StockMovementReason::PartialReturn)->count())->toBe(1)
        ->and($this->ledger->onHand($refused))->toBe(10);
})->with('courier scripts');

it('asks nothing of a parcel that arrived whole or came back whole', function (CourierScript $script) {
    [$order] = parcelOfCountedStock($script);

    $parcel = parcelReportedAs($order, $script, $script->returnedWording());

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('answerPartlyDelivered');

    expect(fn () => $this->ledger->recordPartialReturn($parcel, [$order->lines()->sole()->getKey()], $this->answering))
        ->toThrow(ParcelNotAwaitingAnswer::class);
})->with('courier scripts');

it('puts back only the rest when the courier later says the whole parcel came back', function (CourierScript $script) {
    [$order, $parcel, $kept, $refused] = parcelPartlyDelivered($script);

    sayWhatCameBack($order, [lineFor($order, $refused)]);

    /**
     * And then the Courier announces that the parcel came back after all,
     * weeks after a User settled what they knew of it.
     */
    answeringAfresh();
    $script->fakeTracking($script->returnedWording());

    $this->post(announcementUrl($script), $script->statusAnnouncement($parcel->invoice_reference, $script->returnedWording()))->assertOk();

    /**
     * What goes back is what left and has not already come back. The two
     * scarves are on the shelf once rather than twice, and the jacket the
     * Customer was holding comes back now.
     */
    expect(Shipment::sole()->status)->toBe(ShipmentStatus::Returned)
        ->and(parcelMovements(StockMovementReason::Return)->sole()->quantity)->toBe(3)
        ->and($this->ledger->onHand($kept))->toBe(10)
        ->and($this->ledger->onHand($refused))->toBe(10);
})->with('announcing couriers');
