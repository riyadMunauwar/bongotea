<?php

use App\Enums\AdjustmentDirection;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use App\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ProductStockHistory;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AdjustmentCause;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
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
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
| Adjusting a shelf in one dialog (ADR-0040)
|--------------------------------------------------------------------------
*/

/**
 * A Product sold in sealed Packs of these weights, counted onto the shelf at
 * so many grams where a figure is given.
 */
function teaInPacks(array $packGrams, ?int $held = null): Product
{
    $product = Product::factory()->create(['name' => 'Darjeeling First Flush']);

    foreach ($packGrams as $sort => $grams) {
        Pack::factory()->for($product)->create(['grams' => $grams, 'sort' => $sort]);
    }

    if ($held !== null) {
        app(StockLedger::class)->recordCount($product, $held);
    }

    return $product->refresh();
}

it('offers one Adjust stock action in the theme colour, gated on count stock', function () {
    Livewire::test(ListStockCounts::class)
        ->assertActionVisible('adjust')
        ->assertActionHasColor('adjust', 'primary')
        /** The three verbs it replaced are gone: one act, however the Merchant thinks of it. */
        ->assertActionDoesNotExist('count')
        ->assertActionDoesNotExist('increase')
        ->assertActionDoesNotExist('decrease')
        ->assertActionDoesNotExist('create');

    expect(StockCountResource::getPages())->not->toHaveKey('create');

    $this->actingAs(User::factory()->create());
    $this->get(StockCountResource::getUrl('index'))->assertForbidden();
});

it('takes a product nobody has counted only as counted, an opening count nobody chose a reason for', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);

    Livewire::test(ListStockCounts::class)
        ->mountAction('adjust')
        ->fillForm(['product_id' => $product->getKey()])
        ->assertSchemaComponentExists('on_hand', checkComponentUsing: fn (TextEntry $onHand): bool => $onHand->getState() === 'Not counted yet')
        /** An unknown has nothing to add to or take from. */
        ->assertFormFieldExists('what', fn (Radio $what): bool => array_keys($what->getOptions()) === ['count'])
        ->assertSchemaStateSet(['what' => 'count'])
        ->assertFormFieldHidden('cause')
        ->fillForm(['unit' => 'kg', 'quantity' => 30, 'note' => 'Two boxes in the back'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $count = StockCount::sole();

    expect($count->quantity)->toBe(30000)
        ->and($count->is_opening)->toBeTrue()
        ->and($count->adjustmentCause)->toBeNull()
        ->and($count->note)->toBe('Two boxes in the back')
        /** Attributed to whoever is signed in, never to a field anybody could choose a name in. */
        ->and($count->user->is($this->counter))->toBeTrue()
        ->and($this->ledger->onHand($product))->toBe(30000);
});

it('removes packets of one pack, as one adjustment of exactly that weight', function () {
    $tea = teaInPacks([250], held: 20000);
    $quarter = $tea->packs->firstWhere('grams', 250);

    Livewire::test(ListStockCounts::class)
        ->mountAction('adjust')
        ->fillForm(['product_id' => $tea->getKey(), 'what' => 'remove', 'unit' => $quarter->getKey(), 'quantity' => 2])
        ->assertSchemaComponentExists('after', checkComponentUsing: fn (TextEntry $after): bool => $after->getState() === '19.5 kg (was 20 kg, −0.5 kg)')
        ->fillForm(['cause' => cause('Damaged')->getKey(), 'note' => 'Two packets torn'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $count = StockCount::query()->latest('id')->first();

    /** Two 250 g packets: half a kilogram off the tea's one stock. */
    expect($this->ledger->onHand($tea))->toBe(19500)
        ->and($count->stockMovements->sole()->quantity)->toBe(-500)
        ->and($count->adjustmentCause->name)->toBe('Damaged')
        ->and($count->note)->toBe('Two packets torn');
});

it('adds kilograms to the gram', function () {
    $tea = teaInPacks([250], held: 20000);

    Livewire::test(ListStockCounts::class)
        ->callAction('adjust', ['product_id' => $tea->getKey(), 'what' => 'add', 'unit' => 'kg', 'quantity' => '1.25', 'cause' => cause('Found')->getKey()])
        ->assertHasNoActionErrors();

    expect($this->ledger->onHand($tea))->toBe(21250);
});

it('sets what was counted as the total, in packets', function () {
    $tea = teaInPacks([250], held: 20000);
    $quarter = $tea->packs->firstWhere('grams', 250);

    Livewire::test(ListStockCounts::class)
        ->callAction('adjust', ['product_id' => $tea->getKey(), 'what' => 'count', 'unit' => $quarter->getKey(), 'quantity' => 72, 'cause' => cause('Lost')->getKey()])
        ->assertHasNoActionErrors();

    /** 72 × 250 g, squared against the 20 kg the ledger held. */
    expect($this->ledger->onHand($tea))->toBe(18000)
        ->and(StockMovement::query()->latest('id')->value('quantity'))->toBe(-2000);
});

it('records a count that agrees as checked, asking for no reason', function () {
    $tea = teaInPacks([250], held: 12000);

    Livewire::test(ListStockCounts::class)
        ->mountAction('adjust')
        ->fillForm(['product_id' => $tea->getKey(), 'what' => 'count', 'unit' => 'kg', 'quantity' => 12])
        ->assertFormFieldHidden('cause')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $checked = StockCount::query()->latest('id')->first();

    expect($checked->adjustmentCause)->toBeNull()
        ->and($checked->stockMovements->sole()->quantity)->toBe(0);
});

it('takes whole packets only', function () {
    $tea = teaInPacks([250], held: 20000);

    Livewire::test(ListStockCounts::class)
        ->callAction('adjust', ['product_id' => $tea->getKey(), 'what' => 'remove', 'unit' => $tea->packs->firstWhere('grams', 250)->getKey(), 'quantity' => '1.5', 'cause' => cause('Lost')->getKey()])
        ->assertHasActionErrors(['quantity']);
});

it('refuses adding or removing nothing', function () {
    $tea = teaInPacks([250], held: 20000);

    Livewire::test(ListStockCounts::class)
        ->callAction('adjust', ['product_id' => $tea->getKey(), 'what' => 'remove', 'unit' => 'kg', 'quantity' => 0, 'cause' => cause('Lost')->getKey()])
        ->assertHasActionErrors(['quantity']);

    expect(StockCount::count())->toBe(1);
});

it('offers only the current causes that go the way the change went', function () {
    $tea = teaInPacks([250], held: 20000);
    AdjustmentCause::factory()->retired()->create(['name' => 'Rats', 'direction' => AdjustmentDirection::Removes]);

    $offered = function (string $what) use ($tea): array {
        $options = [];

        Livewire::test(ListStockCounts::class)
            ->mountAction('adjust')
            ->fillForm(['product_id' => $tea->getKey(), 'what' => $what, 'unit' => 'kg', 'quantity' => 1])
            ->assertSchemaComponentExists('cause', checkComponentUsing: function (Select $cause) use (&$options): bool {
                $options = array_values($cause->getOptions());

                return true;
            });

        return $options;
    };

    expect($offered('add'))->toBe(['Found', 'Miscount'])
        ->and($offered('remove'))->toBe(['Damaged', 'Lost', 'Miscount', 'Sample / tasting', 'Personal use']);
});

it('adds a new cause from the dialog, going the way the change went', function () {
    $tea = teaInPacks([250], held: 20000);

    Livewire::test(ListStockCounts::class)
        ->mountAction('adjust')
        ->fillForm(['product_id' => $tea->getKey(), 'what' => 'add', 'unit' => 'kg', 'quantity' => 1])
        ->callAction(TestAction::make('createOption')->schemaComponent('cause'), ['name' => 'Returned by a shop'])
        ->assertHasNoActionErrors();

    expect(AdjustmentCause::query()->where('name', 'Returned by a shop')->sole()->direction)->toBe(AdjustmentDirection::Adds);
});

it('refuses a removal that would take on hand below zero, and writes nothing', function () {
    $tea = teaInPacks([250], held: 3000);

    Livewire::test(ListStockCounts::class)
        ->callAction('adjust', ['product_id' => $tea->getKey(), 'what' => 'remove', 'unit' => 'kg', 'quantity' => 5, 'cause' => cause('Lost')->getKey()])
        ->assertHasActionErrors(['quantity']);

    expect($this->ledger->onHand($tea))->toBe(3000)
        ->and(StockCount::count())->toBe(1);
});

it('applies a removal against the shelf as it stands when saved, not as it stood when opened', function () {
    $tea = teaInPacks([250], held: 20000);

    $dialog = Livewire::test(ListStockCounts::class)
        ->mountAction('adjust')
        ->fillForm(['product_id' => $tea->getKey(), 'what' => 'remove', 'unit' => 'kg', 'quantity' => 2, 'cause' => cause('Damaged')->getKey()]);

    /** A parcel leaves while the dialog is open. */
    $this->ledger->recordAdjustment($tea, -1000, cause('Lost'));

    $dialog->callMountedAction()->assertHasNoActionErrors();

    /** "Two broke" is still true, so two come off what is there now. */
    expect($this->ledger->onHand($tea))->toBe(17000);
});

it('opens from a product row with that product already chosen', function () {
    $tea = teaInPacks([250], held: 20000);

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('adjust')->table($tea), [
            'what' => 'add',
            'unit' => 'kg',
            'quantity' => 1,
            'cause' => cause('Found')->getKey(),
        ])
        ->assertHasNoActionErrors();

    expect($this->ledger->onHand($tea))->toBe(21000);
});

it('does not offer a product nobody can be standing in front of', function () {
    $stocked = Product::factory()->create(['name' => 'Winter Jacket']);
    $withdrawn = Product::factory()->create(['name' => 'Clay mug']);

    $withdrawn->delete();

    Livewire::test(ListStockCounts::class)
        ->mountAction('adjust')
        ->assertSchemaComponentExists(
            'product_id',
            checkComponentUsing: fn (Select $product): bool => in_array($stocked->name, $product->getOptions(), true)
                && ! in_array($withdrawn->name, $product->getOptions(), true),
        );
});

it('lists what was adjusted in the words the dialog used', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    $opening = $this->ledger->recordCount($jacket, 30000, null, null, $this->counter);
    $damaged = $this->ledger->recordCount($jacket, 22000, cause('Damaged'), null, $this->counter);
    $checked = $this->ledger->recordCount($jacket, 22000, null, null, $this->counter);
    $elsewhere = $this->ledger->recordCount($mug, 4000, null, null, $this->counter);

    Livewire::test(ListStockCounts::class)
        /** Newest first without being asked: the last thing adjusted is the thing somebody is asking about. */
        ->assertCanSeeTableRecords([$elsewhere, $checked, $damaged, $opening], inOrder: true)
        ->assertTableColumnFormattedStateSet('change', '-8 kg', $damaged)
        ->assertTableColumnFormattedStateSet('quantity', '22 kg', $damaged)
        ->assertTableColumnStateSet('reason', 'Damaged', $damaged)
        ->assertTableColumnStateSet('reason', 'Opening count', $opening)
        ->assertTableColumnStateSet('reason', 'Checked', $checked)
        ->assertSeeText('Nasrin Akter')
        ->filterTable('reason', 'checked')
        ->assertCanSeeTableRecords([$checked])
        ->assertCanNotSeeTableRecords([$opening, $damaged, $elsewhere])
        ->resetTableFilters()
        /** Offered under its own id, so picking it by name filters by it. */
        ->assertTableFilterExists('reason', fn (SelectFilter $filter): bool => $filter->getOptions()[cause('Damaged')->getKey()] === 'Damaged')
        ->filterTable('reason', (string) cause('Damaged')->getKey())
        ->assertCanSeeTableRecords([$damaged])
        ->assertCanNotSeeTableRecords([$opening, $checked])
        ->resetTableFilters()
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

    $this->ledger->recordCount($counted, 30000, null, null, $this->counter);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$counted, $uncounted])
        ->assertTableColumnStateSet(StockLedger::ON_HAND, 30000, $counted)
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

    $this->ledger->recordCount($wanted, 30000, null, null, $this->counter);

    orderFor($wanted, 4, OrderStatus::Confirmed);
    orderFor($wanted, 7, OrderStatus::Confirmed);

    /** Neither of these is spoken for: nobody has phoned the first back, and the second is not happening at all. */
    orderFor($wanted, 5, OrderStatus::New);
    orderFor($wanted, 9, OrderStatus::Cancelled);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(StockLedger::ON_HAND, 30000, $wanted)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 11000, $wanted)

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
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 4000, $product);

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
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 4000, $product);
});

it('sorts the catalogue by what is held, though the figure is in no column', function () {
    $few = Product::factory()->create();
    $many = Product::factory()->create();

    $this->ledger->recordCount($few, 3000, null, null, $this->counter);
    $this->ledger->recordCount($many, 40000, null, null, $this->counter);

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

    StockMovement::factory()->for($product)->create(['quantity' => 2000]);
    StockMovement::factory()->for($product)->create(['quantity' => -5000]);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(StockLedger::ON_HAND, -3000, $product)
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

    $this->ledger->recordCount($product, 30000, null, null, $this->counter);
    $this->ledger->recordCount($product, 22000, cause('Damaged'), null, $this->counter);
    $this->ledger->recordCount($elsewhere, 4000, null, null, $this->counter);

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
        ['product_id' => $product->getKey(), 'quantity' => 10000, 'unit_cost' => 40000],
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
        ['product_id' => $product->getKey(), 'quantity' => 10000, 'unit_cost' => 40000],
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

    $this->ledger->recordCount($product, 14000, null, null, $this->counter);

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
