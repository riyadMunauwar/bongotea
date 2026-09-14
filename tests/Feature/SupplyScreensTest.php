<?php

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\CreateSupply;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ViewSupply;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ProductStockHistory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyLine;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * The screens a Merchant buys on: the Supplier they record, the delivery they
 * enter line by line, the document it becomes, and what both do to the money
 * figure on their own catalogue.
 *
 * Driven through Livewire against the real admin screens, the way
 * `StockScreensTest` and `ShipmentScreensTest` are: what is asserted is what
 * a User is served, not what a schema class was asked to build.
 */
beforeEach(function (): void {
    $this->buyer = User::factory()->create(['name' => 'Nasrin Akter']);
    $this->actingAs($this->buyer);

    $this->ledger = app(StockLedger::class);
    $this->supplier = Supplier::factory()->create(['name' => 'Mirpur Textiles']);
});

/*
|--------------------------------------------------------------------------
| Who the merchant buys from
|--------------------------------------------------------------------------
*/

it('records a supplier by a name, a phone and a note', function () {
    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'name' => 'Bongo Packaging',
            'phone' => '01712345678',
            'note' => 'Ask for Rana. Closed Fridays.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = Supplier::query()->where('name', 'Bongo Packaging')->sole();

    expect($supplier->phone)->toBe('01712345678')
        ->and($supplier->note)->toBe('Ask for Rana. Closed Fridays.');
});

it('refuses a supplier with no name, because a buying history has to group under something', function () {
    Livewire::test(CreateSupplier::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Supplier::query()->count())->toBe(1);
});

it('lists suppliers with how much has come from each', function () {
    $product = Product::factory()->create();
    $unused = Supplier::factory()->create(['name' => 'Bongo Packaging']);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], null, $this->buyer);

    Livewire::test(ListSuppliers::class)
        ->assertCanSeeTableRecords([$this->supplier, $unused])
        ->assertTableColumnStateSet('supplies_count', 1, $this->supplier)
        ->assertTableColumnStateSet('supplies_count', 0, $unused)
        ->searchTable('Mirpur')
        ->assertCanSeeTableRecords([$this->supplier])
        ->assertCanNotSeeTableRecords([$unused]);
});

/*
|--------------------------------------------------------------------------
| Recording a delivery
|--------------------------------------------------------------------------
*/

it('records a multi-line delivery and raises what is held by it', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    Livewire::test(CreateSupply::class)
        ->fillForm([
            'supplier_id' => $this->supplier->getKey(),
            'supplied_on' => '2026-09-10',
            'reference' => 'INV-2291',
            'lines' => [
                ['product_id' => $jacket->getKey(), 'quantity' => 40, 'unit_cost' => '900.00'],
                ['product_id' => $mug->getKey(), 'quantity' => 12, 'unit_cost' => '150.00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $supply = Supply::sole();

    expect($supply->supplier->is($this->supplier))->toBeTrue()
        ->and($supply->supplied_on->toDateString())->toBe('2026-09-10')
        ->and($supply->reference)->toBe('INV-2291')
        /** Attributed to whoever is signed in, never to a field anybody could choose a name in. */
        ->and($supply->user->is($this->buyer))->toBeTrue()
        ->and($supply->lines)->toHaveCount(2)
        /** Typed in whole currency, stored in minor units like every other money figure. */
        ->and($supply->lines->firstWhere('product_id', $jacket->getKey())->unit_cost)->toBe(90000)
        ->and($this->ledger->onHand($jacket))->toBe(40)
        ->and($this->ledger->onHand($mug))->toBe(12)
        ->and(StockMovement::count())->toBe(2)
        ->and(StockMovement::query()->where('reason', StockMovementReason::Supply)->count())->toBe(2);
});

it('asks for a supplier, a day, a product, a quantity and a cost, and refuses a delivery without them', function () {
    Livewire::test(CreateSupply::class)
        ->fillForm([
            'supplier_id' => null,
            'supplied_on' => null,
            'lines' => [
                ['product_id' => null, 'quantity' => null, 'unit_cost' => null],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors([
            'supplier_id' => 'required',
            'supplied_on' => 'required',
            'lines.0.product_id' => 'required',
            'lines.0.quantity' => 'required',
            'lines.0.unit_cost' => 'required',
        ]);

    expect(Supply::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('refuses a delivery of nothing, which is not a delivery', function () {
    Livewire::test(CreateSupply::class)
        ->fillForm([
            'supplier_id' => $this->supplier->getKey(),
            'supplied_on' => '2026-09-10',
            'lines' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['lines']);

    expect(Supply::count())->toBe(0);
});

it('does not offer a supplier nobody is buying from any more', function () {
    $withdrawn = Supplier::factory()->create(['name' => 'Bongo Packaging']);

    $withdrawn->delete();

    $options = Livewire::test(CreateSupply::class)
        ->instance()
        ->getSchema('form')
        ->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === 'supplier_id')
        ->getOptions();

    expect($options)->toContain('Mirpur Textiles')
        ->and($options)->not->toContain('Bongo Packaging');
});

/*
|--------------------------------------------------------------------------
| Reading what was bought
|--------------------------------------------------------------------------
*/

it('lists deliveries newest first, with what each one cost', function () {
    $product = Product::factory()->create();
    $elsewhere = Supplier::factory()->create(['name' => 'Bongo Packaging']);

    $older = $this->ledger->recordSupply($this->supplier, '2026-09-01', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], 'INV-1', $this->buyer);

    $newer = $this->ledger->recordSupply($elsewhere, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 5, 'unit_cost' => 20000],
    ], 'INV-2', $this->buyer);

    Livewire::test(ListSupplies::class)
        /** The last delivery is the one somebody is asking about. */
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertTableColumnStateSet('total_cost', 100000, $older)
        ->assertTableColumnStateSet('total_cost', 100000, $newer)
        ->assertSeeText('Mirpur Textiles')
        ->assertSeeText('INV-1')
        ->searchTable('INV-2')
        ->assertCanSeeTableRecords([$newer])
        ->assertCanNotSeeTableRecords([$older]);
});

it('reads one delivery as the document it came on', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $jacket->getKey(), 'quantity' => 40, 'unit_cost' => 90000],
    ], 'INV-2291', $this->buyer);

    Livewire::test(ViewSupply::class, ['record' => $supply->getKey()])
        ->assertSeeText('Mirpur Textiles')
        ->assertSeeText('INV-2291')
        ->assertSeeText('Winter Jacket')
        ->assertSeeText('Nasrin Akter')
        ->assertSeeText(Money::format(90000))
        ->assertSeeText(Money::format(40 * 90000));
});

it('offers no way to edit or delete a delivery', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], null, $this->buyer);

    expect(SupplyResource::getPages())->not->toHaveKey('edit')
        ->and(SupplyResource::canEdit($supply))->toBeFalse()
        ->and(SupplyResource::canDelete($supply))->toBeFalse()
        ->and(SupplyResource::canDeleteAny())->toBeFalse();

    Livewire::test(ListSupplies::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(ViewSupply::class, ['record' => $supply->getKey()])
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

/*
|--------------------------------------------------------------------------
| Striking one out
|--------------------------------------------------------------------------
*/

it('voids a wrong delivery from the document it was recorded on, and puts the shelf back', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);

    $this->ledger->recordCount($jacket, 12, AdjustmentCause::OpeningCount, null, $this->buyer);

    /** 500 where somebody meant 50. */
    $mistake = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $jacket->getKey(), 'quantity' => 500, 'unit_cost' => 90000],
    ], 'INV-2291', $this->buyer);

    $document = Livewire::test(ViewSupply::class, ['record' => $mistake->getKey()])
        ->assertActionExists('voidSupply')
        ->mountAction('voidSupply');

    /** What is about to move is named in units of the Merchant's own Product, not as "reverse". */
    expect($document->instance()->getMountedAction()->getModalDescription())
        ->toContain('500 × Winter Jacket');

    $document->callMountedAction()->assertHasNoActionErrors();

    $mistake->refresh();

    expect($mistake->isVoided())->toBeTrue()
        ->and($mistake->voidedBy->name)->toBe('Nasrin Akter')
        ->and($this->ledger->onHand($jacket))->toBe(12);
});

it('offers no way to void a delivery a second time', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 40, 'unit_cost' => 90000],
    ], null, $this->buyer);

    $this->ledger->voidSupply($supply, $this->buyer);

    Livewire::test(ViewSupply::class, ['record' => $supply->getKey()])
        ->assertActionHidden('voidSupply')
        /** And still readable, which is the point of not deleting it. */
        ->assertSeeText('This delivery was voided')
        ->assertSeeText('Nasrin Akter');

    expect(StockMovement::query()->where('reason', StockMovementReason::Void)->count())->toBe(1);
});

it('keeps a voided delivery on the list, and lets a buyer read past it', function () {
    $product = Product::factory()->create();

    $standing = $this->ledger->recordSupply($this->supplier, '2026-09-01', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], 'INV-1', $this->buyer);

    $struck = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 20000],
    ], 'INV-2', $this->buyer);

    $this->ledger->voidSupply($struck, $this->buyer);

    Livewire::test(ListSupplies::class)
        /** Nothing disappears: the User who comes looking for the mistake comes looking here. */
        ->assertCanSeeTableRecords([$struck, $standing])
        ->filterTable('voided', false)
        ->assertCanSeeTableRecords([$standing])
        ->assertCanNotSeeTableRecords([$struck])
        ->filterTable('voided', true)
        ->assertCanSeeTableRecords([$struck])
        ->assertCanNotSeeTableRecords([$standing]);
});

it('leaves a voided delivery out of what a supplier has delivered', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], 'INV-1', $this->buyer);

    $struck = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 20000],
    ], 'INV-2', $this->buyer);

    $this->ledger->voidSupply($struck, $this->buyer);

    /** One delivery stands, so one is what they have delivered. */
    Livewire::test(ListSuppliers::class)
        ->assertTableColumnStateSet('supplies_count', 1, $this->supplier);
});

it('reads both the delivery and its reversal in the product\'s own history', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 40, 'unit_cost' => 90000],
    ], null, $this->buyer);

    $this->ledger->voidSupply($supply, $this->buyer);

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertCanSeeTableRecords(StockMovement::query()->get())
        ->assertSeeText('Supply')
        ->assertSeeText('Void')
        ->assertSeeText('+40')
        ->assertSeeText('-40')
        /** Both stamped at what was paid, so the pair comes to nothing. */
        ->assertSeeText(Money::format(90000));
});

/*
|--------------------------------------------------------------------------
| What the catalogue is worth
|--------------------------------------------------------------------------
*/

it('shows what each product is worth at cost, beside what is held', function () {
    $bought = Product::factory()->create(['name' => 'Winter Jacket']);
    $counted = Product::factory()->create(['name' => 'Clay mug']);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $bought->getKey(), 'quantity' => 40, 'unit_cost' => 90000],
    ], null, $this->buyer);

    /** Counted but never bought: units held, and nobody knows what they cost. */
    $this->ledger->recordCount($counted, 12, AdjustmentCause::OpeningCount, null, $this->buyer);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(StockLedger::ON_HAND, 40, $bought)
        ->assertTableColumnStateSet(StockLedger::VALUE_AT_COST, 40 * 90000, $bought)
        ->assertSeeText(Money::format(40 * 90000))
        /** Unknown rather than free, the same choice On Hand makes against `0`. */
        ->assertTableColumnStateSet(StockLedger::VALUE_AT_COST, null, $counted);
});

it('sorts the catalogue by what it is worth, though the figure is in no column', function () {
    $cheap = Product::factory()->create();
    $dear = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $cheap->getKey(), 'quantity' => 2, 'unit_cost' => 10000],
        ['product_id' => $dear->getKey(), 'quantity' => 40, 'unit_cost' => 90000],
    ], null, $this->buyer);

    Livewire::test(ListProducts::class)
        ->sortTable(StockLedger::VALUE_AT_COST)
        ->assertCanSeeTableRecords([$cheap, $dear], inOrder: true)
        ->sortTable(StockLedger::VALUE_AT_COST, 'desc')
        ->assertCanSeeTableRecords([$dear, $cheap], inOrder: true);
});

it('explains the money figure from the product\'s own history, movement by movement', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);

    $this->ledger->recordSupply($this->supplier, '2026-09-01', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], null, $this->buyer);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 30, 'unit_cost' => 20000],
    ], null, $this->buyer);

    Livewire::test(ProductStockHistory::class, ['record' => $product->getKey()])
        ->assertCanSeeTableRecords($product->stockMovements)
        ->assertSeeText('On hand: 40')
        ->assertSeeText('Value at cost: '.Money::format(700000))
        ->assertSeeText('Supply')
        ->assertSeeText('+30')
        /** Each movement at what that delivery paid, which is what the average is worked out from. */
        ->assertSeeText(Money::format(10000))
        ->assertSeeText(Money::format(20000));
});

it('puts both screens in the inventory cluster, after counting a shelf', function () {
    expect(SupplyResource::getCluster())->toBe(InventoryCluster::class)
        ->and(SupplierResource::getCluster())->toBe(InventoryCluster::class)
        /** Stock Counts 0, Supplies 1, Suppliers 2: the work first, and the reference the other two point at last. */
        ->and(StockCountResource::getNavigationSort())->toBe(0)
        ->and(SupplyResource::getNavigationSort())->toBe(1)
        ->and(SupplierResource::getNavigationSort())->toBe(2);
});

it('reaches a product\'s movements from the delivery that caused them', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 10000],
    ], null, $this->buyer);

    $line = SupplyLine::sole();

    expect($line->stockMovements->sole()->quantity)->toBe(10)
        ->and(StockMovement::sole()->source->is($line))->toBeTrue();
});
