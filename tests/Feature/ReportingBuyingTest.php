<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Supply;
use App\Support\Inventory\StockLedger;
use App\Support\Reporting\Buying;
use App\Support\Reporting\BuyingProductRow;
use App\Support\Reporting\BuyingSupplierRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
|
| A Supply is dated by the day the goods came rather than by the moment
| somebody typed it in, so the window is compared against whole days in the
| Merchant's own timezone — the reading the Visit aggregate already takes, and
| never the UTC instants Orders are cut on.
|
| Nothing is written by hand: every delivery here goes through StockLedger, so
| what these prove is what a Merchant produces by recording an invoice.
*/

function buyingOf(ReportingPreset $preset = ReportingPreset::Last7Days): Buying
{
    return app(ReportingQuery::class)->buying(ReportingWindow::of($preset, reportingNow()));
}

/**
 * A delivery, the way a User records one: a Supplier, the day it came, and a
 * line per Product with a quantity and what each unit cost.
 *
 * @param  list<array{Product, int, int}>  $lines  Product, quantity, unit cost.
 */
function boughtOn(Supplier $supplier, string $day, array $lines): Supply
{
    return app(StockLedger::class)->recordSupply(
        supplier: $supplier,
        suppliedOn: $day,
        lines: array_map(
            fn (array $line): array => [
                'product_id' => $line[0]->getKey(),
                'quantity' => $line[1],
                'unit_cost' => $line[2],
            ],
            $lines,
        ),
    );
}

/**
 * @return list<array{string, int, int, int}>
 */
function buyingBySupplier(Buying $buying): array
{
    return array_map(
        fn (BuyingSupplierRow $row): array => [$row->name(), $row->deliveries, $row->units, $row->cost],
        $buying->bySupplier,
    );
}

/**
 * @return list<array{string, int, int}>
 */
function buyingByProduct(Buying $buying): array
{
    return array_map(
        fn (BuyingProductRow $row): array => [$row->name(), $row->units, $row->cost],
        $buying->byProduct,
    );
}

/**
 * A week of buying across two Suppliers, with a third delivery a day outside
 * the window this screen opens on.
 *
 * Dhaka Traders delivered twice — ten tea at 200.00 and four mugs at 150.00 on
 * the 5th, then six more tea at 220.00 on the 8th — and Sylhet Supply once,
 * five mugs at 140.00 on the 4th.
 *
 * @return array{Supplier, Supplier, Product, Product}
 */
function aWeekOfBuying(): array
{
    $dhaka = Supplier::factory()->create(['name' => 'Dhaka Traders']);
    $sylhet = Supplier::factory()->create(['name' => 'Sylhet Supply']);

    $tea = Product::factory()->create(['name' => 'Sylhet tea']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    boughtOn($dhaka, '2026-09-05', [[$tea, 10, 20000], [$mug, 4, 15000]]);
    boughtOn($dhaka, '2026-09-08', [[$tea, 6, 22000]]);
    boughtOn($sylhet, '2026-09-04', [[$mug, 5, 14000]]);

    boughtOn($dhaka, '2026-09-02', [[$tea, 100, 20000]]);

    return [$dhaka, $sylhet, $tea, $mug];
}

it('reads the window by Supplier — how many deliveries, how many units and what they cost — biggest spend first', function () {
    aWeekOfBuying();

    expect(buyingBySupplier(buyingOf()))->toBe([
        ['Dhaka Traders', 2, 20, 392000],
        ['Sylhet Supply', 1, 5, 70000],
    ]);
});

it('reads the same window by Product, with what a unit averaged', function () {
    aWeekOfBuying();

    $buying = buyingOf();

    expect(buyingByProduct($buying))->toBe([
        ['Sylhet tea', 16, 332000],
        ['Clay mug', 9, 130000],
    ]);

    expect($buying->byProduct[0]->averageUnitCost())->toBe(20750)
        ->and($buying->byProduct[1]->averageUnitCost())->toBe(14444);
});

it('totals the window across every Supplier and Product alike', function () {
    aWeekOfBuying();

    $buying = buyingOf();

    expect($buying->deliveries)->toBe(3)
        ->and($buying->units)->toBe(25)
        ->and($buying->cost)->toBe(462000);
});

it('cuts on the day the goods came, in the Merchant\'s own timezone', function () {
    aWeekOfBuying();

    /** The 2nd is the day before the window opens, and its hundred units are nowhere in it. */
    expect(buyingOf()->units)->toBe(25);

    /** Only the 8th falls in yesterday. */
    expect(buyingBySupplier(buyingOf(ReportingPreset::Yesterday)))->toBe([
        ['Dhaka Traders', 1, 6, 132000],
    ]);
});

it('leaves a Voided delivery out of every buying figure', function () {
    [$dhaka, , $tea] = aWeekOfBuying();

    $mistake = boughtOn($dhaka, '2026-09-06', [[$tea, 500, 20000]]);

    expect(buyingOf()->units)->toBe(525);

    app(StockLedger::class)->voidSupply($mistake);

    $buying = buyingOf();

    expect($buying->units)->toBe(25)
        ->and($buying->cost)->toBe(462000)
        ->and($buying->deliveries)->toBe(3)
        ->and(buyingBySupplier($buying))->toBe([
            ['Dhaka Traders', 2, 20, 392000],
            ['Sylhet Supply', 1, 5, 70000],
        ]);
});

it('keeps a Supplier taken off the list naming the deliveries they made, and says they are gone', function () {
    [, $sylhet] = aWeekOfBuying();

    $sylhet->delete();

    $row = collect(buyingOf()->bySupplier)->firstWhere(fn (BuyingSupplierRow $row): bool => $row->name() === 'Sylhet Supply');

    expect($row)->not->toBeNull()
        ->and($row->isDeleted())->toBeTrue()
        ->and($row->cost)->toBe(70000);
});

it('keeps a Product withdrawn from the catalogue under what was bought of it, and says it is deleted', function () {
    [, , , $mug] = aWeekOfBuying();

    $mug->delete();

    $row = collect(buyingOf()->byProduct)->firstWhere(fn (BuyingProductRow $row): bool => $row->name() === 'Clay mug');

    expect($row)->not->toBeNull()
        ->and($row->isDeleted())->toBeTrue()
        ->and($row->units)->toBe(9);
});

it('reads a window that bought nothing as no rows and nothing spent', function () {
    aWeekOfBuying();

    $buying = buyingOf(ReportingPreset::Today);

    expect($buying->bySupplier)->toBe([])
        ->and($buying->byProduct)->toBe([])
        ->and($buying->deliveries)->toBe(0)
        ->and($buying->units)->toBe(0)
        ->and($buying->cost)->toBe(0);
});
