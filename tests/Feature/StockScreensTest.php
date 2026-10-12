<?php

use App\Enums\AdjustmentCause;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use App\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ProductStockHistory;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\CommittedStock;
use App\Support\Inventory\DaysOfCover;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

/**
 * The screens a Merchant meets the ledger on: the count they record, the list
 * of what has been counted, the On Hand and Days of Cover figures on their own
 * catalogue, and one Product's history.
 *
 * Driven through Livewire against the real admin screens, the way
 * `ShipmentScreensTest` and `OrderFulfilmentTest` are: what is asserted is
 * what a User is served, not what a schema class was asked to build.
 */
beforeEach(function (): void {
    /**
     * Counts shelves and reads the catalogue: `count stock` opens the Stock
     * Count screens this test drives, and `manage catalogue` opens the Products
     * list and Stock History where On Hand, Committed and Days of Cover are
     * read. The Inventory gate is asserted on its own further down.
     */
    $this->counter = User::factory()->countingStock()->managingCatalogue()->create(['name' => 'Nasrin Akter']);
    $this->actingAs($this->counter);

    $this->ledger = app(StockLedger::class);
});

/*
|--------------------------------------------------------------------------
| Counting a shelf
|--------------------------------------------------------------------------
*/

it('records a count and squares the ledger with it, without anybody typing a difference', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);

    Livewire::test(CreateStockCount::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'quantity' => 30,
            'cause' => AdjustmentCause::OpeningCount->value,
            'note' => 'Two boxes in the back',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $count = StockCount::sole();

    expect($count->quantity)->toBe(30)
        ->and($count->cause)->toBe(AdjustmentCause::OpeningCount)
        ->and($count->note)->toBe('Two boxes in the back')
        /** Attributed to whoever is signed in, never to a field anybody could choose a name in. */
        ->and($count->user->is($this->counter))->toBeTrue()
        ->and($this->ledger->onHand($product))->toBe(30)
        ->and(StockMovement::sole()->quantity)->toBe(30);
});

it('counts again and writes a second adjustment measured against the first', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 30, AdjustmentCause::OpeningCount, null, $this->counter);

    Livewire::test(CreateStockCount::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'quantity' => 22,
            'cause' => AdjustmentCause::Damaged->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($this->ledger->onHand($product))->toBe(22)
        ->and(StockMovement::query()->orderBy('id')->pluck('quantity')->all())->toBe([30, -8]);
});

it('takes the first count as an opening count without anybody choosing one', function () {
    $product = Product::factory()->create();

    /** The cause left as the form offers it, which is the ordinary case when a Merchant adopts stock. */
    Livewire::test(CreateStockCount::class)
        ->fillForm(['product_id' => $product->getKey(), 'quantity' => 12])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StockCount::sole()->cause)->toBe(AdjustmentCause::OpeningCount)
        ->and($this->ledger->onHand($product))->toBe(12);
});

it('does not offer a product nobody can be standing in front of', function () {
    $stocked = Product::factory()->create(['name' => 'Winter Jacket']);
    $withdrawn = Product::factory()->create(['name' => 'Clay mug']);

    $withdrawn->delete();

    $options = Livewire::test(CreateStockCount::class)
        ->instance()
        ->getSchema('form')
        ->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === 'product_id')
        ->getOptions();

    expect($options)->toContain($stocked->name)
        ->and($options)->not->toContain($withdrawn->name);
});

it('asks for a product, a quantity and a cause, and refuses a count without them', function () {
    Livewire::test(CreateStockCount::class)
        ->fillForm(['product_id' => null, 'quantity' => null, 'cause' => null])
        ->call('create')
        ->assertHasFormErrors(['product_id' => 'required', 'quantity' => 'required', 'cause' => 'required']);

    expect(StockCount::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('refuses a counted quantity below nothing, which is a figure the ledger concludes rather than one anybody asserts', function () {
    $product = Product::factory()->create();

    Livewire::test(CreateStockCount::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'quantity' => -5,
            'cause' => AdjustmentCause::Lost->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['quantity']);
});

it('lists what has been counted, with what the ledger did about each one', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    $opening = $this->ledger->recordCount($jacket, 30, AdjustmentCause::OpeningCount, null, $this->counter);
    $damaged = $this->ledger->recordCount($jacket, 22, AdjustmentCause::Damaged, null, $this->counter);
    $elsewhere = $this->ledger->recordCount($mug, 4, AdjustmentCause::OpeningCount, null, $this->counter);

    Livewire::test(ListStockCounts::class)
        ->assertCanSeeTableRecords([$damaged, $opening, $elsewhere])
        /** Newest first without being asked: the last thing counted is the thing somebody is asking about. */
        ->assertCanSeeTableRecords([$elsewhere, $damaged, $opening], inOrder: true)
        ->assertSeeText('Winter Jacket')
        ->assertSeeText('Nasrin Akter')
        /** The ledger's own answer beside the count that caused it, signed. */
        ->assertSeeText('-8')
        ->assertSeeText('+30')
        ->searchTable('Clay mug')
        ->assertCanSeeTableRecords([$elsewhere])
        ->assertCanNotSeeTableRecords([$opening, $damaged]);
});

it('offers no way to edit or delete a count', function () {
    $count = StockCount::factory()->create();

    expect(StockCountResource::getPages())->not->toHaveKey('edit')
        ->and(StockCountResource::canEdit($count))->toBeFalse()
        ->and(StockCountResource::canDelete($count))->toBeFalse()
        ->and(StockCountResource::canDeleteAny())->toBeFalse();

    Livewire::test(ListStockCounts::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

/*
|--------------------------------------------------------------------------
| Adjusting a shelf by +/−
|--------------------------------------------------------------------------
*/

it('offers Increase and Decrease in the theme colour, and no exact-count header button, gated on count stock', function () {
    Livewire::test(ListStockCounts::class)
        ->assertActionVisible('increase')
        ->assertActionVisible('decrease')
        /** Both verbs wear the panel's own colour rather than a green/red pair. */
        ->assertActionHasColor('increase', 'primary')
        ->assertActionHasColor('decrease', 'primary')
        /** The exact-count form is left behind its route; it is not surfaced as a header button. */
        ->assertActionDoesNotExist('create');

    /** The actions ride on `count stock` through the cluster: without it the screen they live on is forbidden. */
    $this->actingAs(User::factory()->create());
    $this->get(StockCountResource::getUrl('index'))->assertForbidden();
});

it('increases on hand by exactly what was typed, as one adjustment carrying the reason, note and counter', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 10, AdjustmentCause::OpeningCount, null, $this->counter);

    Livewire::test(ListStockCounts::class)
        ->callAction('increase', [
            'product_id' => $product->getKey(),
            'quantity' => 5,
            'cause' => AdjustmentCause::Found->value,
            'note' => 'Found behind the counter',
        ])
        ->assertHasNoActionErrors();

    $adjustment = StockMovement::query()->where('reason', StockMovementReason::Adjustment)->latest('id')->first();
    $count = StockCount::query()->latest('id')->first();

    expect($this->ledger->onHand($product))->toBe(15)
        /** One Adjustment movement of exactly the delta, never the resulting absolute. */
        ->and($adjustment->quantity)->toBe(5)
        ->and($count->cause)->toBe(AdjustmentCause::Found)
        ->and($count->note)->toBe('Found behind the counter')
        /** Attributed to whoever is signed in, never to a field. */
        ->and($count->user->is($this->counter))->toBeTrue();
});

it('defaults an increase reason to Found, and adopts an uncounted product at the quantity', function () {
    $product = Product::factory()->create();

    /** Never counted: an Increase is welcome and sets On Hand to the delta, the way a first count adopts it. */
    Livewire::test(ListStockCounts::class)
        ->callAction('increase', ['product_id' => $product->getKey(), 'quantity' => 3])
        ->assertHasNoActionErrors();

    expect($this->ledger->onHand($product))->toBe(3)
        /** The reason left as the modal offers it resolves to the enum behind the Select's own default. */
        ->and(StockCount::query()->latest('id')->first()->cause)->toBe(AdjustmentCause::Found);
});

it('decreases on hand by exactly what was typed, defaulting the reason to Damaged', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 10, AdjustmentCause::OpeningCount, null, $this->counter);

    Livewire::test(ListStockCounts::class)
        ->callAction('decrease', ['product_id' => $product->getKey(), 'quantity' => 2])
        ->assertHasNoActionErrors();

    $adjustment = StockMovement::query()->where('reason', StockMovementReason::Adjustment)->latest('id')->first();

    expect($this->ledger->onHand($product))->toBe(8)
        ->and($adjustment->quantity)->toBe(-2)
        ->and(StockCount::query()->latest('id')->first()->cause)->toBe(AdjustmentCause::Damaged);
});

it('filters the reasons per direction and offers Opening count on neither', function () {
    Livewire::test(ListStockCounts::class)
        ->mountAction('increase')
        ->assertSchemaComponentExists(
            'cause',
            checkComponentUsing: fn (Select $reason): bool => array_keys($reason->getOptions()) === [
                AdjustmentCause::Found->value,
                AdjustmentCause::Miscount->value,
            ],
        );

    Livewire::test(ListStockCounts::class)
        ->mountAction('decrease')
        ->assertSchemaComponentExists(
            'cause',
            checkComponentUsing: fn (Select $reason): bool => array_keys($reason->getOptions()) === [
                AdjustmentCause::Damaged->value,
                AdjustmentCause::Lost->value,
                AdjustmentCause::Miscount->value,
            ],
        );
});

it('refuses a decrease that would take on hand below zero, and writes nothing', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 3, AdjustmentCause::OpeningCount, null, $this->counter);

    Livewire::test(ListStockCounts::class)
        ->callAction('decrease', ['product_id' => $product->getKey(), 'quantity' => 5])
        /** Caught before submit, worded against what is on the shelf and pointed at Set exact count. */
        ->assertHasActionErrors(['quantity']);

    /** The count and its opening Adjustment are all that stand: nothing was written by the refused decrease. */
    expect($this->ledger->onHand($product))->toBe(3)
        ->and(StockCount::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('refuses a decrease against a product nobody has counted', function () {
    $product = Product::factory()->create();

    Livewire::test(ListStockCounts::class)
        ->callAction('decrease', ['product_id' => $product->getKey(), 'quantity' => 1])
        ->assertHasActionErrors(['quantity']);

    expect($this->ledger->onHand($product))->toBeNull()
        ->and(StockMovement::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The catalogue
|--------------------------------------------------------------------------
*/

/**
 * An Order for this many of one Product, at whatever point of the capture
 * lifecycle it has reached.
 */
function orderFor(Product $product, int $quantity, OrderStatus $status): Order
{
    $order = Order::factory()->create(['status' => $status]);

    OrderLine::factory()
        ->for($order)
        ->for($product)
        ->create(['product_name' => $product->name, 'unit_price' => $product->price, 'quantity' => $quantity]);

    return $order->refresh();
}

it('shows what each product holds on the products list', function () {
    $counted = Product::factory()->create(['name' => 'Winter Jacket']);
    $uncounted = Product::factory()->create(['name' => 'Clay mug']);

    $this->ledger->recordCount($counted, 30, AdjustmentCause::OpeningCount, null, $this->counter);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$counted, $uncounted])
        ->assertTableColumnStateSet(StockLedger::ON_HAND, 30, $counted)
        /** A Product nobody has counted holds an unknown, which the column draws as nothing rather than as zero. */
        ->assertTableColumnStateSet(StockLedger::ON_HAND, null, $uncounted)
        ->assertTableColumnExists(
            StockLedger::ON_HAND,
            fn (TextColumn $column): bool => $column->getPlaceholder() === '—',
            $uncounted,
        );
});

it('shows what is already spoken for beside what is held', function () {
    $wanted = Product::factory()->create(['name' => 'Winter Jacket']);
    $quiet = Product::factory()->create(['name' => 'Clay mug']);

    $this->ledger->recordCount($wanted, 30, AdjustmentCause::OpeningCount, null, $this->counter);

    orderFor($wanted, 4, OrderStatus::Confirmed);
    orderFor($wanted, 7, OrderStatus::Confirmed);

    /** Neither of these is spoken for: nobody has phoned the first back, and the second is not happening at all. */
    orderFor($wanted, 5, OrderStatus::New);
    orderFor($wanted, 9, OrderStatus::Cancelled);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(StockLedger::ON_HAND, 30, $wanted)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 11, $wanted)

        /**
         * Nothing spoken for reads as nothing spoken for, where On Hand beside
         * it would read as unknown: this is counted off the Orders themselves
         * and is never a figure nobody has established.
         */
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 0, $quiet);
});

it('stops counting an order as spoken for once a courier is carrying it', function () {
    $product = Product::factory()->create();

    $order = orderFor($product, 4, OrderStatus::Confirmed);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 4, $product);

    /** The units are in a van rather than on a shelf, and the ledger has them as gone (ADR-0021). */
    Shipment::factory()->for($order)->create();

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 0, $product);
});

it('never counts an order whose parcel already reached the door', function () {
    $product = Product::factory()->create();

    /** A delivered Order has no Active Shipment either, and counting it would leave the figure climbing for ever. */
    Shipment::factory()->for(orderFor($product, 4, OrderStatus::Confirmed))->delivered()->create();

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 0, $product);
});

it('counts an order as spoken for again when its parcel comes back', function () {
    $product = Product::factory()->create();

    Shipment::factory()->for(orderFor($product, 4, OrderStatus::Confirmed))->returned()->create();

    /** A parcel that came back is an Order to send again, which is the same answer the queue gives about it (ADR-0018). */
    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 4, $product);
});

it('sorts the catalogue by what is held, though the figure is in no column', function () {
    $few = Product::factory()->create();
    $many = Product::factory()->create();

    $this->ledger->recordCount($few, 3, AdjustmentCause::OpeningCount, null, $this->counter);
    $this->ledger->recordCount($many, 40, AdjustmentCause::OpeningCount, null, $this->counter);

    /** The sum is an alias on the query the list runs, and ordering by it is what proves the alias survives the sort. */
    Livewire::test(ListProducts::class)
        ->sortTable(StockLedger::ON_HAND)
        ->assertCanSeeTableRecords([$few, $many], inOrder: true)
        ->sortTable(StockLedger::ON_HAND, 'desc')
        ->assertCanSeeTableRecords([$many, $few], inOrder: true);
});

it('shows how long each shelf will last beside how much is on it', function () {
    $running = shelfOf('Sylhet tea', 30);
    parcelShippedOn($running, 28, reportingNow()->subDays(10));

    $comfortable = shelfOf('Winter jacket', 280);
    parcelShippedOn($comfortable, 28, reportingNow()->subDays(10));

    $this->travelTo(reportingNow());

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(DaysOfCover::COVER, 2.0, $running)
        ->assertTableColumnFormattedStateSet(DaysOfCover::COVER, '2.0', $running)
        ->assertTableColumnStateSet(DaysOfCover::COVER, 252.0, $comfortable)

        /** Loud under the Merchant's own threshold, which is the line Needs Attention draws too. */
        ->assertTableColumnExists(
            DaysOfCover::COVER,
            fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'danger'
                && $column->getWeight($column->getState()) === FontWeight::Bold,
            $running,
        )
        ->assertTableColumnExists(
            DaysOfCover::COVER,
            fn (TextColumn $column): bool => $column->getColor($column->getState()) === null,
            $comfortable,
        );
});

it('says nothing rather than zero or for ever where there is no cover to report', function () {
    $uncounted = shelfOf('Clay mug');
    parcelShippedOn($uncounted, 20, reportingNow()->subDays(10));

    /** Counted, and nothing has gone out of it: a dormant Product is not an infinitely safe one. */
    $dormant = shelfOf('Winter jacket', 40);

    $this->travelTo(reportingNow());

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(DaysOfCover::COVER, null, $uncounted)
        ->assertTableColumnStateSet(DaysOfCover::COVER, null, $dormant)
        ->assertTableColumnExists(
            DaysOfCover::COVER,
            fn (TextColumn $column): bool => $column->getPlaceholder() === '—',
            $dormant,
        );
});

it('draws what is held below nothing loudly, because an unrecorded supply must announce itself', function () {
    $product = Product::factory()->create();

    StockMovement::factory()->for($product)->create(['quantity' => 2]);
    StockMovement::factory()->for($product)->create(['quantity' => -5]);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(StockLedger::ON_HAND, -3, $product)
        ->assertTableColumnExists(
            StockLedger::ON_HAND,
            fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'danger'
                && $column->getWeight($column->getState()) === FontWeight::Bold,
            $product,
        );
});

/*
|--------------------------------------------------------------------------
| One product's history
|--------------------------------------------------------------------------
*/

it('reads every movement of one product, with its reason, quantity, date and the person behind it', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);
    $elsewhere = Product::factory()->create(['name' => 'Clay mug']);

    $this->ledger->recordCount($product, 30, AdjustmentCause::OpeningCount, null, $this->counter);
    $this->ledger->recordCount($product, 22, AdjustmentCause::Damaged, null, $this->counter);
    $this->ledger->recordCount($elsewhere, 4, AdjustmentCause::OpeningCount, null, $this->counter);

    $movements = $product->stockMovements()->orderBy('id')->get();

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertCanSeeTableRecords($movements)
        ->assertCanNotSeeTableRecords($elsewhere->stockMovements)
        ->assertSeeText('On hand: 22')
        ->assertSeeText('Adjustment')
        ->assertSeeText('Nasrin Akter')
        ->assertSeeText('+30')
        ->assertSeeText('-8')
        /** Nothing has been supplied, so no average was known to stamp (ADR-0022) — and unknown is never drawn as free. */
        ->assertSeeText('—')
        ->assertDontSeeText(Money::format(0));
});

it('reads a written-off parcel as money rather than as a quantity to multiply', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);
    $order = Order::factory()->create();

    OrderLine::factory()->for($order)->for($product)->create(['quantity' => 3]);

    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 40000],
    ]);

    $shipment = Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'consignment_reference' => 'CN-7781',
    ]);

    $shipment->abandon($this->counter);

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertSeeText('Loss')

        /** What the parcel actually cost, in money, on the row that names it a loss. */
        ->assertTableColumnStateSet('value', -120000, $product->stockMovements()->where('reason', StockMovementReason::Loss)->sole())
        ->assertSeeText(Money::format(-120000))

        /** And the shelf is exactly where the Courier left it: the units left once, at Booked. */
        ->assertSeeText('On hand: 7')
        ->assertSeeText('Value at cost: '.Money::format(280000));
});

it('names the User who said which items came back, beside what those units were worth', function () {
    $answering = User::factory()->create(['name' => 'Tanvir Hasan']);

    $product = Product::factory()->create(['name' => 'Winter Jacket']);
    $order = Order::factory()->create();

    $line = OrderLine::factory()->for($order)->for($product)->create(['quantity' => 3]);

    $this->ledger->recordSupply(Supplier::factory()->create(), now(), [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 40000],
    ]);

    $parcel = Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'consignment_reference' => 'CN-7781',
        'status' => ShipmentStatus::PartlyDelivered,
    ]);

    $this->ledger->recordPartialReturn($parcel, [$line->getKey()], $answering);

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertSeeText('Partial return')

        /**
         * A name on a movement, which nothing a Courier causes carries. It is
         * the whole point of this one: nobody but a person could have said
         * which items came back, so a surprising figure has somebody to ask
         * about it.
         */
        ->assertSeeText('Tanvir Hasan')

        /** At what those units cost when they left, so the round trip revalued nothing. */
        ->assertTableColumnStateSet('value', 120000, $product->stockMovements()->where('reason', StockMovementReason::PartialReturn)->sole())
        ->assertSeeText('On hand: 10');
});

it('says nothing rather than zero for a product nobody has counted', function () {
    $product = Product::factory()->create();

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertSeeText('On hand: —')
        ->assertSeeText('Days of cover: —')
        ->assertSeeText('Nothing has moved yet');
});

it('says how long the shelf will last on the screen a shelf running out opens onto', function () {
    $product = shelfOf('Sylhet tea', 30);
    parcelShippedOn($product, 28, reportingNow()->subDays(10));

    $this->travelTo(reportingNow());

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertSeeText('On hand: 2')
        ->assertSeeText('Days of cover: 2.0');
});

it('keeps a soft-deleted product\'s history readable', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 14, AdjustmentCause::OpeningCount, null, $this->counter);

    $product->delete();

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertCanSeeTableRecords($product->stockMovements)
        ->assertSeeText('On hand: 14');
});

it('offers no way to move stock from the history screen', function () {
    $product = Product::factory()->create();

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

/*
|--------------------------------------------------------------------------
| Getting around
|--------------------------------------------------------------------------
*/

it('reaches the history from the product a merchant is already looking at', function () {
    $product = Product::factory()->create();

    expect(ProductResource::getUrl('stock', ['record' => $product]))
        ->toEndWith('/'.$product->getKey().'/stock');

    $this->get(ProductResource::getUrl('edit', ['record' => $product]))
        ->assertOk()
        ->assertSee(ProductResource::getUrl('stock', ['record' => $product]));
});

/*
|--------------------------------------------------------------------------
| The words a merchant reads
|--------------------------------------------------------------------------
*/

it('shows the stock count resource to a merchant as Stock Adjustments', function () {
    /** Filament label overrides only — the model, table and slug underneath are untouched. */
    expect(StockCountResource::getNavigationLabel())->toBe('Stock Adjustments')
        ->and(StockCountResource::getPluralModelLabel())->toBe('Stock Adjustments')
        ->and(StockCountResource::getModelLabel())->toBe('Stock Adjustment');

    /** And the plural reaches the User: the list screen titles itself in the merchant's word. */
    Livewire::test(ListStockCounts::class)
        ->assertSee('Stock Adjustments');
});

it('shows the supply resource to a merchant as Purchases', function () {
    expect(SupplyResource::getNavigationLabel())->toBe('Purchases')
        ->and(SupplyResource::getPluralModelLabel())->toBe('Purchases')
        ->and(SupplyResource::getModelLabel())->toBe('Purchase');

    Livewire::test(ListSupplies::class)
        ->assertSee('Purchases');
});

it('leaves the cluster and the Suppliers resource named as they were', function () {
    /** The relabel is two resources wide and no wider: the working area and where goods come from keep their names. */
    expect(InventoryCluster::getClusterBreadcrumb())->toBe('Inventory')
        ->and(SupplierResource::getNavigationLabel())->toBe('Suppliers');
});

it('takes one sidebar line, as a working area above reports and settings', function () {
    expect(InventoryCluster::getNavigationSort())->toBe(10)
        ->and(StockCountResource::getCluster())->toBe(InventoryCluster::class);

    /**
     * An Owner draws the sidebar in full: every top-level entry is gated on
     * its own Permission now, and an Owner passes each check regardless, so
     * this pins Inventory's place among them without having to name every
     * other gate — which is `AdminShellTest`'s job, and holds the same order
     * for the panel as a whole.
     */
    $this->actingAs(User::factory()->owner()->create());

    $labels = collect(filament()->getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel())
        ->all();

    /** A cluster contributes one entry under its own label and never its resources. */
    expect($labels)->toBe(['Dashboard', 'Orders', 'Products', 'Pages', 'Enquiries', 'Inventory', 'Reports', 'Settings']);
});
