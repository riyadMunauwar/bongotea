<?php

use App\Enums\AdjustmentCause;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Inventory\StockLedger;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\StockHoldingRow;
use App\Support\Reporting\StockHoldings;

/*
| The Stock report is the one result in this module that takes no Reporting
| Window: it answers what is held right now, and there is no span of days
| behind that. So there is no reportingNow() here and no preset anywhere,
| which is the whole reason the screen draws no window control.
|
| Nothing is written to the ledger by hand. Every figure below is arrived at
| through StockLedger — the only thing allowed to write a movement — so what
| these prove is what a Merchant produces by counting a shelf and recording a
| delivery.
*/

function stockHoldings(): StockHoldings
{
    return app(ReportingQuery::class)->stockHoldings();
}

/**
 * @return list<array{string, int|null, int|null}>
 */
function stockHoldingRows(StockHoldings $holdings): array
{
    return array_map(
        fn (StockHoldingRow $row): array => [$row->name(), $row->onHand, $row->valueAtCost],
        $holdings->rows,
    );
}

/**
 * @return array<string, StockHoldingRow>
 */
function stockHoldingsByName(): array
{
    $rows = [];

    foreach (stockHoldings()->rows as $row) {
        $rows[$row->name()] = $row;
    }

    return $rows;
}

/**
 * A delivery of one Product at one price, through the ledger, so cost enters
 * the system the way it does in life.
 */
function deliveredIntoStock(Product $product, int $quantity, int $unitCost): void
{
    app(StockLedger::class)->recordSupply(
        supplier: Supplier::factory()->create(),
        suppliedOn: '2026-09-05',
        lines: [['product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_cost' => $unitCost]],
    );
}

it('lists what every Product holds and what it is worth, the most capital first', function () {
    $tea = Product::factory()->create(['name' => 'Sylhet tea']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    deliveredIntoStock($tea, 10, 20000);
    deliveredIntoStock($mug, 4, 15000);

    expect(stockHoldingRows(stockHoldings()))->toBe([
        ['Sylhet tea', 10, 200000],
        ['Clay mug', 4, 60000],
    ]);
});

it('adds up what is held altogether, over the Products whose worth is known', function () {
    $tea = Product::factory()->create(['name' => 'Sylhet tea']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);
    Product::factory()->create(['name' => 'Uncounted candle']);

    deliveredIntoStock($tea, 10, 20000);
    deliveredIntoStock($mug, 4, 15000);

    expect(stockHoldings()->valueHeld)->toBe(260000);
});

it('reads a Product nobody has counted as nothing, never as zero', function () {
    Product::factory()->create(['name' => 'Uncounted candle']);

    expect(stockHoldingRows(stockHoldings()))->toBe([['Uncounted candle', null, null]]);
});

it('holds an empty shelf somebody counted apart from a shelf nobody has visited', function () {
    $counted = Product::factory()->create(['name' => 'Counted candle']);
    Product::factory()->create(['name' => 'Uncounted candle']);

    app(StockLedger::class)->recordCount($counted, 0, AdjustmentCause::OpeningCount);

    $rows = stockHoldingsByName();

    expect($rows['Counted candle']->onHand)->toBe(0)
        ->and($rows['Uncounted candle']->onHand)->toBeNull();
});

it('says a counted Product nothing was ever bought for is worth an unknown rather than nothing', function () {
    $product = Product::factory()->create(['name' => 'Counted candle']);

    app(StockLedger::class)->recordCount($product, 6, AdjustmentCause::OpeningCount);

    expect(stockHoldingRows(stockHoldings()))->toBe([['Counted candle', 6, null]])
        ->and(stockHoldings()->valueHeld)->toBeNull();
});

it('goes back to an unknown worth when the only delivery behind it is voided', function () {
    $product = Product::factory()->create(['name' => 'Sylhet tea']);

    $supply = app(StockLedger::class)->recordSupply(
        supplier: Supplier::factory()->create(),
        suppliedOn: '2026-09-05',
        lines: [['product_id' => $product->getKey(), 'quantity' => 10, 'unit_cost' => 20000]],
    );

    app(StockLedger::class)->voidSupply($supply);

    expect(stockHoldingRows(stockHoldings()))->toBe([['Sylhet tea', 0, null]])
        ->and(stockHoldings()->valueHeld)->toBeNull();
});

it('keeps a Product withdrawn from the catalogue while it still holds units, and says it is deleted', function () {
    $retired = Product::factory()->create(['name' => 'Retired candle']);

    deliveredIntoStock($retired, 5, 10000);

    $retired->delete();

    $row = stockHoldings()->rows[0];

    expect($row->name())->toBe('Retired candle')
        ->and($row->isDeleted())->toBeTrue()
        ->and($row->onHand)->toBe(5)
        ->and($row->valueAtCost)->toBe(50000)
        ->and(stockHoldings()->valueHeld)->toBe(50000);
});

it('drops a withdrawn Product once it holds nothing, so a retired line does not sit on the report forever', function () {
    $retired = Product::factory()->create(['name' => 'Retired candle']);
    Product::factory()->create(['name' => 'Sylhet tea']);

    app(StockLedger::class)->recordCount($retired, 0, AdjustmentCause::OpeningCount);

    $retired->delete();

    expect(array_keys(stockHoldingsByName()))->toBe(['Sylhet tea']);
});

it('is empty on an installation with no Products at all', function () {
    expect(stockHoldings()->rows)->toBe([])
        ->and(stockHoldings()->valueHeld)->toBeNull();
});
