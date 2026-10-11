<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Support\Reporting\Margin;
use App\Support\Reporting\MarginRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Illuminate\Database\Eloquent\Builder;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
|
| Both halves of margin are counted at Capture, over the Orders `Sales`
| itself counts, which is what these tests are mostly about: the revenue on
| this report must be `Sales` to the minor unit, and the cost must be the
| figures stamped on the very same Orders. A Cancelled Order leaves both
| sides at once.
|
| A cost is stamped on the Line rather than read from the ledger now
| (ADR-0022), so these tests stamp it on the Line as Capture does. What the
| stamp is worked out from is `OrderCapture`'s business and is proved in
| `CheckoutCaptureTest`.
*/

function marginOf(ReportingPreset $preset = ReportingPreset::Last7Days): Margin
{
    return app(ReportingQuery::class)->margin(ReportingWindow::of($preset, reportingNow()));
}

/**
 * A Product's worth of an Order, the way Capture writes one: the name and
 * the price copied onto the line, the cost of the day stamped beside it, the
 * line total the observer's. A null cost is the unknown a Product nothing has
 * been recorded buying stamps.
 */
function soldAtCost(Order $order, Product $product, int $quantity = 1, ?int $unitCost = null): OrderLine
{
    return OrderLine::factory()->for($order)->create([
        'product_id' => $product->getKey(),
        'product_name' => $product->name,
        'unit_price' => $product->price,
        'unit_cost' => $unitCost,
        'quantity' => $quantity,
    ]);
}

/**
 * @return list<array{string, int, int, int|null, int|null}>
 */
function marginRows(Margin $margin): array
{
    return array_map(
        fn (MarginRow $row): array => [$row->name(), $row->units, $row->revenue, $row->cost, $row->margin()],
        $margin->rows,
    );
}

/**
 * Sales as the Dashboard draws it, over the same window, so the two figures
 * can be compared rather than asserted against a number typed twice.
 */
function salesOver(ReportingPreset $preset = ReportingPreset::Last7Days): int
{
    return app(ReportingQuery::class)->overview(ReportingWindow::of($preset, reportingNow()))->figures->sales;
}

/**
 * A week of selling with what it cost beside it.
 *
 * Two tea at 300.00 that cost 180.00 each and one at the same price that cost
 * 200.00 by the time it sold; one mug at 100.00 that cost 90.00. Every Order
 * carries the standing 60.00 Delivery Charge, and a fourth Order the day
 * before the window opens is outside all of it.
 *
 * @return array{Product, Product}
 */
function aWeekOfSelling(): array
{
    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);
    $mug = Product::factory()->create(['name' => 'Clay mug', 'price' => 10000]);

    soldAtCost(orderCapturedAt('2026-09-05 12:00', subtotal: 60000), $tea, 2, 18000);
    soldAtCost(orderCapturedAt('2026-09-08 12:00', subtotal: 30000, status: OrderStatus::Confirmed), $tea, 1, 20000);
    soldAtCost(orderCapturedAt('2026-09-06 12:00', subtotal: 10000), $mug, 1, 9000);

    soldAtCost(orderCapturedAt('2026-09-02 12:00', subtotal: 30000), $tea, 1, 18000);

    return [$tea, $mug];
}

it('reads the window by Product — what it earned, what it cost and what is left — best margin first', function () {
    aWeekOfSelling();

    expect(marginRows(marginOf()))->toBe([
        ['Sylhet tea', 3, 90000, 56000, 34000],
        ['Clay mug', 1, 10000, 9000, 1000],
    ]);
});

it('counts revenue exactly as Sales counts it, Delivery Charge and all', function () {
    aWeekOfSelling();

    $margin = marginOf();

    expect($margin->revenue)->toBe(salesOver())
        ->and($margin->revenue)->toBe(118000)
        ->and($margin->deliveryCharge)->toBe(18000);

    /** The rows carry the goods alone; the Delivery Charge sits beside them and is what makes up the difference. */
    expect($margin->goodsRevenue())->toBe(100000)
        ->and($margin->goodsRevenue() + $margin->deliveryCharge)->toBe($margin->revenue);
});

it('weighs the very Orders Sales counts, so its Kilograms are the Dashboard\'s Kilograms Sold', function () {
    [$tea] = aWeekOfSelling();

    soldAtCost(orderCapturedAt('2026-09-07 12:00', status: OrderStatus::Cancelled), $tea, 10, 18000);

    $kilogramsSold = app(ReportingQuery::class)->overview(ReportingWindow::of(ReportingPreset::Last7Days, reportingNow()))->figures->kilograms;

    expect(marginOf()->units)->toBe($kilogramsSold)
        ->and($kilogramsSold)->toBe(4);
});

it('is Sales over the window less the stamped costs of the very same Orders', function () {
    aWeekOfSelling();

    $margin = marginOf();

    $window = ReportingWindow::of(ReportingPreset::Last7Days, reportingNow());

    $stamped = OrderLine::query()
        ->whereHas('order', fn (Builder $order): Builder => $order
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('created_at', '>=', $window->start->toDateTimeString())
            ->where('created_at', '<', $window->end->toDateTimeString()))
        ->whereNotNull('unit_cost')
        ->get()
        ->sum(fn (OrderLine $line): int => $line->unit_cost * $line->quantity);

    expect($margin->cost)->toBe($stamped)
        ->and($margin->margin())->toBe(salesOver() - $stamped)
        ->and($margin->margin())->toBe(53000);
});

it('drops a Cancelled Order out of revenue and cost together', function () {
    [$tea] = aWeekOfSelling();

    $cancelled = orderCapturedAt('2026-09-07 12:00', subtotal: 300000, status: OrderStatus::Cancelled);
    soldAtCost($cancelled, $tea, 10, 18000);

    $margin = marginOf();

    /** Neither half of it is anywhere: the revenue still agrees with Sales, and the cost never rose. */
    expect($margin->revenue)->toBe(salesOver())
        ->and($margin->revenue)->toBe(118000)
        ->and($margin->cost)->toBe(65000)
        ->and($margin->margin())->toBe(53000)
        ->and(marginRows($margin)[0])->toBe(['Sylhet tea', 3, 90000, 56000, 34000]);
});

it('reads a Product nothing was recorded buying as unknown margin rather than as all profit', function () {
    $rug = Product::factory()->create(['name' => 'Rangpur rug', 'price' => 90000]);

    soldAtCost(orderCapturedAt('2026-09-05 12:00', subtotal: 90000), $rug, 1, null);

    $row = marginOf()->rows[0];

    expect($row->name())->toBe('Rangpur rug')
        ->and($row->revenue)->toBe(90000)
        ->and($row->cost)->toBeNull()
        ->and($row->margin())->toBeNull()
        ->and($row->hasUnknownCost())->toBeTrue();
});

it('sorts a Product whose margin is unknown below every Product whose margin is known', function () {
    aWeekOfSelling();

    $rug = Product::factory()->create(['name' => 'Rangpur rug', 'price' => 90000]);
    soldAtCost(orderCapturedAt('2026-09-05 12:00', subtotal: 900000), $rug, 10, null);

    /** It earned the most of anything here and is still last: unknown is not a margin of nine hundred. */
    expect(array_map(fn (MarginRow $row): string => $row->name(), marginOf()->rows))
        ->toBe(['Sylhet tea', 'Clay mug', 'Rangpur rug']);
});

it('counts the stamped part of a half-costed Product and says the rest is unknown', function () {
    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);

    soldAtCost(orderCapturedAt('2026-09-05 12:00', subtotal: 60000), $tea, 2, 18000);
    soldAtCost(orderCapturedAt('2026-09-06 12:00', subtotal: 30000), $tea, 1, null);

    $margin = marginOf();
    $row = $margin->rows[0];

    expect($row->units)->toBe(3)
        ->and($row->costedUnits)->toBe(2)
        ->and($row->cost)->toBe(36000)
        ->and($row->hasUnknownCost())->toBeTrue();

    /** The window says the same thing about itself, which is what stops the total reading as fact. */
    expect($margin->everythingCosted())->toBeFalse()
        ->and($margin->costedUnits)->toBe(2)
        ->and($margin->units)->toBe(3);
});

it('reads a giveaway that cost something as a loss with no rate, never as breaking even', function () {
    $sample = Product::factory()->create(['name' => 'Sample sachet', 'price' => 0, 'mrp' => 5000]);

    soldAtCost(orderCapturedAt('2026-09-05 12:00', subtotal: 0, attributes: ['delivery_charge' => 0]), $sample, 5, 2000);

    $margin = marginOf();
    $row = $margin->rows[0];

    /** It earned nothing and cost 100.00, so it lost 100.00 — and a share of no revenue is unknown, not 0%. */
    expect($row->revenue)->toBe(0)
        ->and($row->cost)->toBe(10000)
        ->and($row->margin())->toBe(-10000)
        ->and($row->marginRate())->toBeNull();

    expect($margin->revenue)->toBe(0)
        ->and($margin->margin())->toBe(-10000)
        ->and($margin->marginRate())->toBeNull();
});

it('reads a window where nothing was costed at all as unknown cost and unknown margin', function () {
    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);

    soldAtCost(orderCapturedAt('2026-09-05 12:00', subtotal: 30000), $tea, 1, null);

    $margin = marginOf();

    expect($margin->revenue)->toBe(salesOver())
        ->and($margin->cost)->toBeNull()
        ->and($margin->margin())->toBeNull();
});

it('keeps a Product withdrawn from the catalogue under what it earned, and says it is deleted', function () {
    [, $mug] = aWeekOfSelling();

    $mug->delete();

    $row = collect(marginOf()->rows)->firstWhere(fn (MarginRow $row): bool => $row->name() === 'Clay mug');

    expect($row)->not->toBeNull()
        ->and($row->isDeleted())->toBeTrue()
        ->and($row->margin())->toBe(1000);
});

it('reads a window that sold nothing as no rows, nothing earned and nothing made', function () {
    aWeekOfSelling();

    $margin = marginOf(ReportingPreset::Today);

    expect($margin->rows)->toBe([])
        ->and($margin->revenue)->toBe(0)
        ->and($margin->deliveryCharge)->toBe(0)
        ->and($margin->cost)->toBeNull()
        ->and($margin->margin())->toBeNull();
});
