<?php

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Models\Pack;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyLine;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\SupplyAlreadyVoided;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Buying: what recording a Supply does to what is held, and what it does to
 * what a unit is worth.
 *
 * Cost enters the system here and nowhere else. Every figure asserted below
 * is worked out from rows the ledger wrote — there is no average column, no
 * cost field on a Product and nothing to backfill (ADR-0022), which is the
 * whole of why a Supply recorded today cannot move what last month said.
 */
beforeEach(function (): void {
    $this->ledger = app(StockLedger::class);
    $this->buyer = User::factory()->create(['name' => 'Nasrin Akter']);
    $this->supplier = Supplier::factory()->create(['name' => 'Mirpur Textiles']);
});

/**
 * A delivery of one Product, in the shape the ledger takes lines in.
 *
 * @return list<array{product_id: int, quantity: int, unit_cost: int}>
 */
function deliveryOf(Product $product, int $quantity, int $unitCost): array
{
    return [['product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_cost' => $unitCost]];
}

/*
|--------------------------------------------------------------------------
| Recording a delivery
|--------------------------------------------------------------------------
*/

it('records a supply as a document with a line per product, and raises what is held there and then', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    $supply = $this->ledger->recordSupply(
        supplier: $this->supplier,
        suppliedOn: '2026-09-10',
        lines: [
            ['product_id' => $jacket->getKey(), 'quantity' => 40000, 'unit_cost' => 90000],
            ['product_id' => $mug->getKey(), 'quantity' => 12000, 'unit_cost' => 15000],
        ],
        reference: 'INV-2291',
        recordedBy: $this->buyer,
    );

    expect($supply->supplier->is($this->supplier))->toBeTrue()
        ->and($supply->supplied_on->toDateString())->toBe('2026-09-10')
        ->and($supply->reference)->toBe('INV-2291')
        ->and($supply->user->name)->toBe('Nasrin Akter')
        ->and($supply->lines)->toHaveCount(2)
        ->and($supply->totalCost())->toBe(40 * 90000 + 12 * 15000)
        /** There is no receiving step to forget: the stock is up the moment the document exists. */
        ->and($this->ledger->onHand($jacket))->toBe(40000)
        ->and($this->ledger->onHand($mug))->toBe(12000);
});

it('writes one movement per line, caused by the line and stamped with what that line paid', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 40000, 90000), 'INV-2291', $this->buyer);

    $movement = StockMovement::sole();
    $line = SupplyLine::sole();

    expect($movement->quantity)->toBe(40000)
        ->and($movement->reason)->toBe(StockMovementReason::Supply)
        ->and($movement->unit_cost)->toBe(90000)
        ->and($movement->user->name)->toBe('Nasrin Akter')
        /** The line rather than the document, so a delivery listing one Product twice is two arrivals of it. */
        ->and($movement->source->is($line))->toBeTrue()
        ->and($line->supply->is($supply))->toBeTrue();
});

it('takes the same product twice in one delivery as two arrivals of it', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 10000, 'unit_cost' => 10000],
        ['product_id' => $product->getKey(), 'quantity' => 5000, 'unit_cost' => 12000],
    ], null, $this->buyer);

    expect($this->ledger->onHand($product))->toBe(15000)
        ->and(StockMovement::count())->toBe(2);
});

it('writes the document and its movements together or writes neither', function () {
    $product = Product::factory()->create();

    /** The ledger failing where it writes the movement, which is the half a delivery cannot survive without. */
    StockMovement::creating(function (): never {
        throw new RuntimeException('The ledger could not be written to.');
    });

    expect(fn () => $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 40000, 90000), null, $this->buyer))
        ->toThrow(RuntimeException::class);

    expect(Supply::count())->toBe(0)
        ->and(SupplyLine::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($this->ledger->onHand($product))->toBeNull();
});

it('refuses a delivery of nothing at all', function () {
    expect(fn () => $this->ledger->recordSupply($this->supplier, '2026-09-10', [], null, $this->buyer))
        ->toThrow(InvalidArgumentException::class);

    expect(Supply::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A delivery that came packed
|--------------------------------------------------------------------------
*/

it('records the Pack a line came in and how many packets it was, into the one pool of grams', function () {
    $product = Product::factory()->create();
    $pack = Pack::factory()->for($product)->create(['grams' => 250]);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 2500, 'unit_cost' => 40000, 'pack_id' => $pack->getKey()],
    ], null, $this->buyer);

    $line = SupplyLine::sole();

    expect($line->pack->is($pack))->toBeTrue()
        ->and($line->pack_grams)->toBe(250)
        ->and($line->packets)->toBe(10)
        ->and($this->ledger->onHand($product))->toBe(2500);
});

it('keeps the packing a line was recorded in after its Pack is gone', function () {
    $product = Product::factory()->create();
    $pack = Pack::factory()->for($product)->create(['grams' => 100]);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 2000, 'unit_cost' => 40000, 'pack_id' => $pack->getKey()],
    ], null, $this->buyer);

    $pack->delete();

    $line = SupplyLine::sole();

    expect($line->pack_id)->toBeNull()
        ->and($line->pack_grams)->toBe(100)
        ->and($line->packets)->toBe(20);
});

it('records loose weight with no Pack and invents no packet count', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 2500, 40000), null, $this->buyer);

    $line = SupplyLine::sole();

    expect($line->pack_id)->toBeNull()
        ->and($line->pack_grams)->toBeNull()
        ->and($line->packets)->toBeNull();
});

it('refuses a weight its Pack cannot make exactly, and writes nothing', function () {
    $product = Product::factory()->create();
    $pack = Pack::factory()->for($product)->create(['grams' => 400]);

    expect(fn () => $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 1000, 'unit_cost' => 40000, 'pack_id' => $pack->getKey()],
    ], null, $this->buyer))->toThrow(InvalidArgumentException::class);

    expect(Supply::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('refuses a Pack belonging to another Product, and writes nothing', function () {
    $product = Product::factory()->create();
    $elsewhere = Pack::factory()->create(['grams' => 250]);

    expect(fn () => $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $product->getKey(), 'quantity' => 2500, 'unit_cost' => 40000, 'pack_id' => $elsewhere->getKey()],
    ], null, $this->buyer))->toThrow(InvalidArgumentException::class);

    expect(Supply::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What a unit is worth
|--------------------------------------------------------------------------
*/

it('knows no cost for a product nobody has bought', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 30000, AdjustmentCause::OpeningCount, null, $this->buyer);

    /** Unknown rather than free: a Product with no known cost must not present itself as infinitely profitable. */
    expect($this->ledger->averageCost($product))->toBeNull()
        ->and($this->ledger->valueAtCost($product))->toBeNull()
        ->and(StockMovement::sole()->unit_cost)->toBeNull();
});

it('rolls the weighted average forward across two supplies at different prices', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), null, $this->buyer);

    expect($this->ledger->averageCost($product))->toBe(10000);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 30000, 20000), null, $this->buyer);

    /** (10 × 100.00 + 30 × 200.00) ÷ 40 = 175.00, and the money held is what was paid for all forty. */
    expect($this->ledger->averageCost($product))->toBe(17500)
        ->and($this->ledger->onHand($product))->toBe(40000)
        ->and($this->ledger->valueAtCost($product))->toBe(700000);
});

it('leaves an earlier movement stamped at what it was worth then, whatever is supplied afterwards', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), null, $this->buyer);

    /** Something moves while the average is 100.00 — the count finds two short, and the Adjustment is stamped at that. */
    $this->ledger->recordCount($product, 8000, AdjustmentCause::Damaged, null, $this->buyer);

    $adjustment = StockMovement::query()->where('reason', StockMovementReason::Adjustment)->sole();

    expect($adjustment->unit_cost)->toBe(10000);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 30000, 20000), null, $this->buyer);

    /** The average has moved on; the rows it was worked out from have not. */
    expect($adjustment->fresh()->unit_cost)->toBe(10000)
        ->and(StockMovement::query()->orderBy('id')->pluck('unit_cost')->all())->toBe([10000, 10000, 20000]);
});

it('stamps an adjustment with the average in force rather than with anything anybody paid', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), null, $this->buyer);
    $this->ledger->recordSupply($this->supplier, '2026-09-02', deliveryOf($product, 30000, 20000), null, $this->buyer);

    /** Four found on a second shelf, entering at what a unit is worth rather than at either price paid. */
    $this->ledger->recordCount($product, 44000, AdjustmentCause::Found, null, $this->buyer);

    $adjustment = StockMovement::query()->where('reason', StockMovementReason::Adjustment)->sole();

    expect($adjustment->quantity)->toBe(4000)
        ->and($adjustment->unit_cost)->toBe(17500)
        /** Units entering at the average leave it exactly where it was. */
        ->and($this->ledger->averageCost($product))->toBe(17500)
        ->and($this->ledger->valueAtCost($product))->toBe(44 * 17500);
});

it('blends units nobody recorded buying into the average rather than standing them outside it', function () {
    $product = Product::factory()->create();

    /** Thirty on the shelf before anything was ever bought through the system. */
    $this->ledger->recordCount($product, 30000, AdjustmentCause::OpeningCount, null, $this->buyer);

    $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 10000, 20000), null, $this->buyer);

    expect($this->ledger->onHand($product))->toBe(40000)
        /** Forty units and 2,000.00 recorded paid for them: 50.00 a unit, which is what the ledger can account for. */
        ->and($this->ledger->averageCost($product))->toBe(5000)
        ->and($this->ledger->valueAtCost($product))->toBe(200000);
});

it('never reports a value below nothing on a shelf that holds something', function () {
    $product = Product::factory()->create();

    /** The ordinary way a Merchant adopts stock: count first, buy afterwards, count again. */
    $this->ledger->recordCount($product, 30000, AdjustmentCause::OpeningCount, null, $this->buyer);
    $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 10000, 20000), null, $this->buyer);
    $this->ledger->recordCount($product, 5000, AdjustmentCause::Lost, null, $this->buyer);

    /**
     * The thirty-five that went left at what a unit was worth — 50.00 — and
     * not at the 200.00 the ten cost, which is what would take more money out
     * of the ledger than was ever put into it.
     */
    expect($this->ledger->onHand($product))->toBe(5000)
        ->and($this->ledger->averageCost($product))->toBe(5000)
        ->and($this->ledger->valueAtCost($product))->toBe(25000)
        /** What is held, at what a unit is worth: the two figures cannot contradict each other. */
        ->and($this->ledger->valueAtCost($product))->toBe(5 * 5000);
});

it('keeps what a unit was worth after the last of it has gone out', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), null, $this->buyer);

    /** Everything supplied written off, which empties the shelf without making the price paid unknowable. */
    $this->ledger->recordCount($product, 0, AdjustmentCause::Lost, null, $this->buyer);

    expect($this->ledger->onHand($product))->toBe(0)
        ->and($this->ledger->averageCost($product))->toBe(10000)
        ->and($this->ledger->valueAtCost($product))->toBe(0);
});

it('rounds an average that does not divide, once, at the moment it is stamped', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 3000, 100000), null, $this->buyer);
    $this->ledger->recordSupply($this->supplier, '2026-09-02', deliveryOf($product, 2000, 100100), null, $this->buyer);

    /** 5002.00 over five units is 1000.40 a unit, and a movement carries whole minor units like every other money figure. */
    expect($this->ledger->averageCost($product))->toBe(100040);
});

it('counts one product\'s cost and never another\'s', function () {
    $bought = Product::factory()->create();
    $elsewhere = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($bought, 10000, 10000), null, $this->buyer);

    expect($this->ledger->averageCost($elsewhere))->toBeNull()
        ->and($this->ledger->valueAtCost($elsewhere))->toBeNull();
});

it('reads what a whole list of products is worth in one query', function () {
    $bought = Product::factory()->create();
    $uncosted = Product::factory()->create();

    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($bought, 10000, 10000), null, $this->buyer);
    $this->ledger->recordCount($uncosted, 5000, AdjustmentCause::OpeningCount, null, $this->buyer);

    $products = $this->ledger->withValueAtCost(Product::query())->get()->keyBy('id');

    expect($products[$bought->id]->{StockLedger::VALUE_AT_COST})->toEqual(100000)
        /** The same unknown the single read answers with, arrived at by the same absence of costed rows. */
        ->and($products[$uncosted->id]->{StockLedger::VALUE_AT_COST})->toBeNull()
        /** And the Product's own columns survived the subquery. */
        ->and($products[$bought->id]->name)->toBe($bought->name);
});

/*
|--------------------------------------------------------------------------
| Striking one out
|--------------------------------------------------------------------------
*/

it('puts a product back exactly where it stood before a supply it should never have had', function () {
    $product = Product::factory()->create();

    /** The shelf as it stood: counted, and bought for once at a price of its own. */
    $this->ledger->recordCount($product, 12000, AdjustmentCause::OpeningCount, null, $this->buyer);
    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 20000, 10000), 'INV-1', $this->buyer);

    $onHandBefore = $this->ledger->onHand($product);
    $valueBefore = $this->ledger->valueAtCost($product);
    $averageBefore = $this->ledger->averageCost($product);

    /** And then somebody types 500 where they meant 50. */
    $mistake = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 500000, 20000), 'INV-2', $this->buyer);

    expect($this->ledger->onHand($product))->toBe(532000);

    $this->ledger->voidSupply($mistake, $this->buyer);

    /** Precisely where it stood, not near it: the reversal took back what that line paid. */
    expect($this->ledger->onHand($product))->toBe($onHandBefore)
        ->and($this->ledger->valueAtCost($product))->toBe($valueBefore)
        ->and($this->ledger->averageCost($product))->toBe($averageBefore);

    /** And both sets of rows are still there to be read together. */
    expect(StockMovement::query()->orderBy('id')->get()->map(fn (StockMovement $movement): array => [
        $movement->reason->value, $movement->quantity, $movement->unit_cost,
    ])->all())->toBe([
        ['adjustment', 12000, null],
        ['supply', 20000, 10000],
        ['supply', 500000, 20000],
        ['void', -500000, 20000],
    ]);
});

it('marks the struck-out delivery with a time and a name, and leaves it readable', function () {
    $product = Product::factory()->create();
    $auditor = User::factory()->create(['name' => 'Farhana Islam']);

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 40000, 90000), 'INV-2291', $this->buyer);

    $voided = $this->ledger->voidSupply($supply, $auditor);

    expect($voided->isVoided())->toBeTrue()
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->voidedBy->name)->toBe('Farhana Islam')
        /** A second name beside the first: what it is for is that the two are rarely the same person. */
        ->and($voided->user->name)->toBe('Nasrin Akter')
        /** Nothing disappears and nothing is corrected — the document still says what was typed. */
        ->and(Supply::count())->toBe(1)
        ->and($voided->reference)->toBe('INV-2291')
        ->and($voided->lines->sole()->quantity)->toBe(40000);
});

it('reverses every line of a delivery, including the same product listed twice', function () {
    $jacket = Product::factory()->create();
    $mug = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', [
        ['product_id' => $jacket->getKey(), 'quantity' => 40000, 'unit_cost' => 90000],
        ['product_id' => $mug->getKey(), 'quantity' => 12000, 'unit_cost' => 15000],
        ['product_id' => $mug->getKey(), 'quantity' => 5000, 'unit_cost' => 18000],
    ], 'INV-2291', $this->buyer);

    $this->ledger->voidSupply($supply, $this->buyer);

    /** One reversal per line, caused by the line it undoes, at the price that line paid. */
    expect(StockMovement::query()->where('reason', StockMovementReason::Void)->count())->toBe(3)
        ->and($this->ledger->onHand($jacket))->toBe(0)
        ->and($this->ledger->onHand($mug))->toBe(0)
        /** Nothing was ever bought for it after all, so its worth is unknown rather than nothing. */
        ->and($this->ledger->valueAtCost($jacket))->toBeNull()
        ->and(SupplyLine::query()->get()->every(
            fn (SupplyLine $line): bool => $line->stockMovements()->count() === 2,
        ))->toBeTrue();
});

it('takes back what the voided delivery paid rather than a blended average', function () {
    $product = Product::factory()->create();

    $cheap = $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), 'INV-1', $this->buyer);
    $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 10000, 20000), 'INV-2', $this->buyer);

    /** Blended while both stand: (10 × 100.00 + 10 × 200.00) ÷ 20. */
    expect($this->ledger->averageCost($product))->toBe(15000);

    $this->ledger->voidSupply($cheap, $this->buyer);

    /**
     * What is left is the delivery that really happened, at its own price.
     * Reversing at the average in force would have left 150.00 standing over
     * ten units nobody ever bought for that.
     */
    expect($this->ledger->onHand($product))->toBe(10000)
        ->and($this->ledger->averageCost($product))->toBe(20000)
        ->and($this->ledger->valueAtCost($product))->toBe(200000);
});

it('leaves a product with no known cost where the only delivery of it was voided', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 40000, 90000), 'INV-2291', $this->buyer);

    $this->ledger->voidSupply($supply, $this->buyer);

    /**
     * Back to knowing nothing about what it costs, which is where it stood
     * before the delivery was typed. Quoting 900.00 would be quoting a
     * delivery the Merchant has just struck out, and reading nothing would
     * make a Product that was never bought look free (ADR-0022).
     */
    expect($this->ledger->averageCost($product))->toBeNull()
        ->and($this->ledger->valueAtCost($product))->toBeNull()
        /** The rows are still there, and still sum to what is on the shelf. */
        ->and($this->ledger->onHand($product))->toBe(0)
        ->and(StockMovement::count())->toBe(2);

    /** And the list reads the same unknown the single figure does. */
    $listed = $this->ledger->withValueAtCost(Product::query())->findOrFail($product->getKey());

    expect($listed->{StockLedger::VALUE_AT_COST})->toBeNull();
});

it('keeps quoting the last price that was really paid, not the one on a voided delivery', function () {
    $product = Product::factory()->create();

    /** Bought once at 100.00 and all of it gone again, so the shelf is empty but the price is known. */
    $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), 'INV-1', $this->buyer);
    $this->ledger->recordCount($product, 0, AdjustmentCause::Damaged, null, $this->buyer);

    $mistake = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 5000, 20000), 'INV-2', $this->buyer);

    $this->ledger->voidSupply($mistake, $this->buyer);

    /** An empty shelf falls back to the last cost stamped, and 200.00 was never paid for anything. */
    expect($this->ledger->onHand($product))->toBe(0)
        ->and($this->ledger->averageCost($product))->toBe(10000);
});

it('refuses to void the same delivery twice', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 40000, 90000), null, $this->buyer);

    $this->ledger->voidSupply($supply, $this->buyer);

    expect(fn () => $this->ledger->voidSupply($supply->fresh(), $this->buyer))->toThrow(SupplyAlreadyVoided::class)
        /** Nothing to reverse a second time, and nothing written trying. */
        ->and(StockMovement::query()->where('reason', StockMovementReason::Void)->count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(0);
});

it('writes the reversal and the mark together or writes neither', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 40000, 90000), null, $this->buyer);

    /** The half that takes the units back out fails, which is the half a void cannot survive without. */
    StockMovement::creating(function (StockMovement $movement): void {
        if ($movement->reason === StockMovementReason::Void) {
            throw new RuntimeException('The ledger could not be written to.');
        }
    });

    expect(fn () => $this->ledger->voidSupply($supply, $this->buyer))->toThrow(RuntimeException::class);

    expect($supply->fresh()->isVoided())->toBeFalse()
        ->and($this->ledger->onHand($product))->toBe(40000);
});

it('reads voided and standing deliveries apart, so a buying figure can leave one out', function () {
    $product = Product::factory()->create();

    $standing = $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), 'INV-1', $this->buyer);
    $struck = $this->ledger->recordSupply($this->supplier, '2026-09-10', deliveryOf($product, 10000, 20000), 'INV-2', $this->buyer);

    $this->ledger->voidSupply($struck, $this->buyer);

    expect(Supply::query()->notVoided()->pluck('reference')->all())->toBe(['INV-1'])
        ->and(Supply::query()->voided()->pluck('reference')->all())->toBe(['INV-2'])
        ->and($standing->fresh()->isVoided())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| History never rewrites itself
|--------------------------------------------------------------------------
*/

it('is the only thing in the application that strikes a supply out', function () {
    /**
     * The mark and the movements that answer for it are one transaction; a
     * second hand writing the mark alone would be a struck-out delivery with
     * stock still on the shelf from it.
     *
     * Anything assigned into either column, which the lookahead separates
     * from `Supply`'s own `'voided_at' => 'datetime'` cast. The whitespace is
     * matched possessively so the lookahead cannot simply back up onto the
     * space and pass.
     */
    $marking = "/'voided_(?:at|by)'\s*+=>\s*+(?!')/";

    $writers = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => preg_match($marking, $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR)))
        ->values()
        ->all();

    expect($writers)->toBe(['Support/Inventory/StockLedger.php']);
});

it('refuses to edit or delete a supply or one of its lines', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), 'INV-1', $this->buyer);
    $line = $supply->lines->sole();

    expect(fn () => $supply->update(['reference' => 'INV-2']))->toThrow(LogicException::class)
        ->and(fn () => $supply->delete())->toThrow(LogicException::class)
        ->and(fn () => $line->update(['quantity' => 500000]))->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class)
        ->and($supply->fresh()->reference)->toBe('INV-1')
        ->and($line->fresh()->quantity)->toBe(10000);
});

it('keeps a supplier\'s deliveries readable after they are taken off the list', function () {
    $product = Product::factory()->create();

    $supply = $this->ledger->recordSupply($this->supplier, '2026-09-01', deliveryOf($product, 10000, 10000), null, $this->buyer);

    $this->supplier->delete();

    expect($this->supplier->trashed())->toBeTrue()
        ->and($supply->fresh()->supplier->name)->toBe('Mirpur Textiles')
        ->and($this->ledger->onHand($product))->toBe(10000);
});
