<?php

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The ledger itself: what On Hand is, where it comes from, and what a Stock
 * Count does to it.
 *
 * Nothing here types a quantity onto a Product, because nothing in the system
 * can. Every figure asserted is the sum of rows the ledger wrote, which is
 * the whole of what makes it worth believing (ADR-0021).
 */
beforeEach(function (): void {
    $this->ledger = app(StockLedger::class);
    $this->counter = User::factory()->create(['name' => 'Nasrin Akter']);
});

/*
|--------------------------------------------------------------------------
| What is held
|--------------------------------------------------------------------------
*/

it('holds an unknown quantity of a product nobody has counted, and no movements at all', function () {
    $product = Product::factory()->create();

    expect($this->ledger->onHand($product))->toBeNull()
        ->and(StockMovement::count())->toBe(0);
});

it('holds the sum of a product\'s movements and never a figure of its own', function () {
    $product = Product::factory()->create();

    StockMovement::factory()->for($product)->create(['quantity' => 40]);
    StockMovement::factory()->for($product)->create(['quantity' => -15]);

    expect($this->ledger->onHand($product))->toBe(25);

    /** And there is no column it could have been written to instead. */
    expect(Schema::getColumnListing('products'))->not->toContain('quantity', 'on_hand');
});

it('counts one product\'s movements and never another\'s', function () {
    $counted = Product::factory()->create();
    $elsewhere = Product::factory()->create();

    StockMovement::factory()->for($counted)->create(['quantity' => 12]);
    StockMovement::factory()->for($elsewhere)->create(['quantity' => 99]);

    expect($this->ledger->onHand($counted))->toBe(12);
});

it('lets what is held go below nothing, because an unrecorded supply must announce itself', function () {
    $product = Product::factory()->create();

    StockMovement::factory()->for($product)->create(['quantity' => 2]);
    StockMovement::factory()->for($product)->create(['quantity' => -5]);

    expect($this->ledger->onHand($product))->toBe(-3);
});

it('reads what is held for a whole list of products in one query', function () {
    $counted = Product::factory()->create();
    $uncounted = Product::factory()->create();

    StockMovement::factory()->for($counted)->create(['quantity' => 7]);

    $products = $this->ledger->withOnHand(Product::query())->get()->keyBy('id');

    expect($products[$counted->id]->{StockLedger::ON_HAND})->toEqual(7)
        /** The same unknown the single read answers with, arrived at by the same absence of rows. */
        ->and($products[$uncounted->id]->{StockLedger::ON_HAND})->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Counting
|--------------------------------------------------------------------------
*/

it('takes a first count as the opening balance, attributed to whoever counted', function () {
    $product = Product::factory()->create();

    $count = $this->ledger->recordCount($product, 30, AdjustmentCause::OpeningCount, 'Two boxes in the back', $this->counter);

    expect($this->ledger->onHand($product))->toBe(30)
        ->and($count->quantity)->toBe(30)
        ->and($count->cause)->toBe(AdjustmentCause::OpeningCount)
        ->and($count->note)->toBe('Two boxes in the back')
        ->and($count->user->name)->toBe('Nasrin Akter');

    $movement = StockMovement::sole();

    expect($movement->quantity)->toBe(30)
        ->and($movement->reason)->toBe(StockMovementReason::Adjustment)
        ->and($movement->user->name)->toBe('Nasrin Akter')
        ->and($movement->source->is($count))->toBeTrue()
        /** Unknown rather than free: nothing has been supplied, so there is no average to stamp (ADR-0022). */
        ->and($movement->unit_cost)->toBeNull();
});

it('writes the difference between what was counted and what the ledger held', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 30, AdjustmentCause::OpeningCount, null, $this->counter);
    $this->ledger->recordCount($product, 22, AdjustmentCause::Damaged, 'Water damage', $this->counter);

    expect($this->ledger->onHand($product))->toBe(22)
        ->and(StockMovement::query()->orderBy('id')->pluck('quantity')->all())->toBe([30, -8]);
});

it('measures a second count against the first rather than against nothing', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 12, AdjustmentCause::OpeningCount, null, $this->counter);
    $this->ledger->recordCount($product, 19, AdjustmentCause::Found, null, $this->counter);

    expect($this->ledger->onHand($product))->toBe(19)
        ->and(StockMovement::query()->orderBy('id')->pluck('quantity')->all())->toBe([12, 7]);
});

it('records a count that agrees with the ledger as an adjustment of nothing', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 12, AdjustmentCause::OpeningCount, null, $this->counter);
    $this->ledger->recordCount($product, 12, AdjustmentCause::Miscount, null, $this->counter);

    /** Somebody looked and the ledger was right, which is a thing worth being able to read. */
    expect($this->ledger->onHand($product))->toBe(12)
        ->and(StockMovement::query()->orderBy('id')->pluck('quantity')->all())->toBe([12, 0]);
});

it('puts a genuinely empty shelf into the ledger, which nothing else could tell from an uncounted one', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 0, AdjustmentCause::OpeningCount, null, $this->counter);

    expect($this->ledger->onHand($product))->toBe(0)
        ->and(StockMovement::count())->toBe(1);
});

it('writes the count and its adjustment together or writes neither', function () {
    $product = Product::factory()->create();

    /** The ledger failing where it writes the movement, which is the half a count cannot survive without. */
    StockMovement::creating(function (): never {
        throw new RuntimeException('The ledger could not be written to.');
    });

    expect(fn () => $this->ledger->recordCount($product, 5, AdjustmentCause::OpeningCount, null, $this->counter))
        ->toThrow(RuntimeException::class);

    /** A count on the record with no movement behind it would be a shelf somebody looked at and a figure that never heard about it. */
    expect(StockCount::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('is the only thing in the application that writes a movement', function () {
    /** Every way a row could be made: the model's own writers, and the relations that write through it. */
    $writing = '/StockMovement::(create|insert|forceCreate|firstOrCreate|updateOrCreate|make)|stockMovements\(\)->(create|save|saveMany|insert|make|createMany)/';

    $writers = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => preg_match($writing, $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR)))
        ->values()
        ->all();

    /**
     * The invariant the whole ledger rests on (ADR-0021). A second writer
     * would be a movement with no stamped cost or no cause behind it, found
     * months later as a figure nobody can account for.
     */
    expect($writers)->toBe(['Support/Inventory/StockLedger.php']);
});

/*
|--------------------------------------------------------------------------
| History never rewrites itself
|--------------------------------------------------------------------------
*/

it('refuses to edit or delete a movement', function () {
    $movement = StockMovement::factory()->create(['quantity' => 5]);

    expect(fn () => $movement->update(['quantity' => 500]))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class)
        ->and($movement->fresh()->quantity)->toBe(5);
});

it('refuses to edit or delete a count', function () {
    $count = StockCount::factory()->create(['quantity' => 5]);

    expect(fn () => $count->update(['quantity' => 500]))->toThrow(LogicException::class)
        ->and(fn () => $count->delete())->toThrow(LogicException::class)
        ->and($count->fresh()->quantity)->toBe(5);
});

it('keeps a soft-deleted product\'s movements and still answers what it held', function () {
    $product = Product::factory()->create();

    $this->ledger->recordCount($product, 14, AdjustmentCause::OpeningCount, null, $this->counter);

    $product->delete();

    expect($product->trashed())->toBeTrue()
        ->and(StockMovement::count())->toBe(1)
        ->and($this->ledger->onHand($product))->toBe(14)
        ->and(StockMovement::sole()->product->is($product))->toBeTrue();
});
