<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Support\Reporting\ProductRanking;
use App\Support\Reporting\ProductRankingRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
*/

function productRankingOf(ReportingPreset $preset = ReportingPreset::Last7Days): ProductRanking
{
    return app(ReportingQuery::class)->productRanking(ReportingWindow::of($preset, reportingNow()));
}

/**
 * A Product's worth of an Order, the way Capture writes one: the name and
 * the price copied onto the line, the line total the observer's.
 */
function soldOn(Order $order, Product $product, int $quantity = 1, ?string $name = null): OrderLine
{
    return OrderLine::factory()->for($order)->create([
        'product_id' => $product->getKey(),
        'product_name' => $name ?? $product->name,
        'unit_price' => $product->price,
        'quantity' => $quantity,
    ]);
}

/**
 * @return list<array{string, int, int}>
 */
function productRankingRows(ProductRanking $ranking): array
{
    return array_map(
        fn (ProductRankingRow $row): array => [$row->name(), $row->units, $row->revenue],
        $ranking->rows,
    );
}

it('merges the Lines of every Order by Product, sums units and revenue, and puts the highest revenue first', function () {
    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);
    $mug = Product::factory()->create(['name' => 'Clay mug', 'price' => 10000]);

    soldOn(orderCapturedAt('2026-09-05 12:00'), $tea, 2);
    soldOn(orderCapturedAt('2026-09-06 12:00', status: OrderStatus::Confirmed), $tea, 1);
    soldOn(orderCapturedAt('2026-09-06 12:00'), $mug, 4);

    expect(productRankingRows(productRankingOf()))->toBe([
        ['Sylhet tea', 3, 90000],
        ['Clay mug', 4, 40000],
    ]);
});

it('reads only the Lines of Orders that count: not Cancelled, not deleted, and inside the window', function () {
    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);

    soldOn(orderCapturedAt('2026-09-05 12:00'), $tea, 1);
    soldOn(orderCapturedAt('2026-09-05 13:00', status: OrderStatus::Cancelled), $tea, 5);
    soldOn(orderCapturedAt('2026-09-02 23:59'), $tea, 7);

    $deleted = orderCapturedAt('2026-09-06 12:00');
    soldOn($deleted, $tea, 9);
    $deleted->delete();

    expect(productRankingRows(productRankingOf()))->toBe([['Sylhet tea', 1, 30000]]);
});

it('keeps a Product deleted for good under the name its Lines recorded, and tells two of them apart', function () {
    $gone = Product::factory()->create(['name' => 'Winter shawl', 'price' => 50000]);
    $alsoGone = Product::factory()->create(['name' => 'Summer hat', 'price' => 20000]);

    soldOn(orderCapturedAt('2026-09-05 12:00'), $gone, 1);
    soldOn(orderCapturedAt('2026-09-06 12:00'), $gone, 1);
    soldOn(orderCapturedAt('2026-09-06 13:00'), $alsoGone, 1);

    $gone->forceDelete();
    $alsoGone->forceDelete();

    $rows = productRankingOf()->rows;

    expect(productRankingRows(productRankingOf()))->toBe([
        ['Winter shawl', 2, 100000],
        ['Summer hat', 1, 20000],
    ])
        ->and($rows[0]->product)->toBeNull()
        ->and($rows[0]->isDeleted())->toBeFalse();
});

it('names a soft-deleted Product as its Lines recorded it, marked deleted, whatever it was renamed to on the way out', function () {
    $retired = Product::factory()->create(['name' => 'Retired candle', 'price' => 15000]);

    soldOn(orderCapturedAt('2026-09-05 12:00'), $retired, 2);

    $retired->update(['name' => 'DO NOT SELL — old stock']);
    $retired->delete();

    $row = productRankingOf()->rows[0];

    expect($row->name())->toBe('Retired candle')
        ->and($row->isDeleted())->toBeTrue()
        ->and($row->units)->toBe(2)
        ->and($row->revenue)->toBe(30000);
});

it('merges a Product renamed since it sold into one row, under the name it now carries', function () {
    $product = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);

    soldOn(orderCapturedAt('2026-09-05 12:00'), $product, 1, name: 'Sylhet tea');
    soldOn(orderCapturedAt('2026-09-06 12:00'), $product, 1, name: 'Sylhet tea, loose');

    $product->update(['name' => 'Sylhet tea, loose']);

    expect(productRankingRows(productRankingOf()))->toBe([['Sylhet tea, loose', 2, 60000]]);
});

it('is empty on a window that sold nothing', function () {
    soldOn(orderCapturedAt('2026-09-02 12:00'), Product::factory()->create());

    expect(productRankingOf()->rows)->toBe([]);
});
