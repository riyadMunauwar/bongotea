<?php

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyLine;
use App\Support\Inventory\Grams;
use App\Support\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * The ledger counts grams (ADR-0035), and the Merchant should not be able to
 * tell: every figure a screen drew before the switch is the figure it draws
 * after it.
 *
 * Cost stays per kilogram. A row's money is grams × cost per kg ÷ 1000,
 * rounded half away from zero once over a sum — pinned here, because it is the
 * one rounding the switch introduced.
 */
beforeEach(function (): void {
    $this->ledger = app(StockLedger::class);
});

it('converts every existing ledger row to grams without moving a figure a screen draws', function () {
    $migration = require database_path('migrations/2026_10_13_105709_convert_stock_ledger_to_grams.php');

    /** Back to the kilogram ledger, and rows written the way it wrote them. */
    $migration->down();

    $assam = Product::factory()->create();
    $darjeeling = Product::factory()->create();
    $supply = Supply::factory()->for(Supplier::factory())->create();

    $movement = fn (Product $product, int $kilograms, StockMovementReason $reason, string $sourceType, int $sourceId, ?int $unitCost): bool => DB::table('stock_movements')->insert([
        'product_id' => $product->getKey(),
        'quantity' => $kilograms,
        'reason' => $reason->value,
        'unit_cost' => $unitCost,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([[10, 45000], [5, 48333]] as [$kilograms, $unitCost]) {
        $lineId = DB::table('supply_lines')->insertGetId([
            'supply_id' => $supply->getKey(),
            'product_id' => $assam->getKey(),
            'quantity' => $kilograms,
            'unit_cost' => $unitCost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $movement($assam, $kilograms, StockMovementReason::Supply, (new SupplyLine)->getMorphClass(), $lineId, $unitCost);
    }

    /** The blend after both deliveries: (10 × 45000 + 5 × 48333) ÷ 15. */
    $movement($assam, -4, StockMovementReason::Sale, (new Shipment)->getMorphClass(), 999, 46111);

    $countId = DB::table('stock_counts')->insertGetId([
        'product_id' => $assam->getKey(),
        'quantity' => 9,
        'cause' => AdjustmentCause::Lost->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $movement($assam, -2, StockMovementReason::Adjustment, (new StockCount)->getMorphClass(), $countId, 46111);

    /** Counted before anything was ever bought: units with no cost. */
    $uncostedCountId = DB::table('stock_counts')->insertGetId([
        'product_id' => $darjeeling->getKey(),
        'quantity' => 3,
        'cause' => AdjustmentCause::OpeningCount->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $movement($darjeeling, 3, StockMovementReason::Adjustment, (new StockCount)->getMorphClass(), $uncostedCountId, null);

    $line = OrderLine::factory()->create(['product_id' => $assam->getKey(), 'quantity' => 4, 'unit_cost' => 46111]);

    /** The figures as the kilogram ledger drew them: On Hand as a whole number, value as Σ(quantity × unit_cost), the blend over them. */
    $kilogramLedger = fn (Product $product): array => (function (object $row): array {
        return [
            'on hand' => number_format((int) $row->units),
            'value' => (int) $row->costed > 0 ? (int) $row->value : null,
            'average' => (int) $row->costed > 0 ? (int) round($row->value / $row->units) : null,
        ];
    })(DB::table('stock_movements')->where('product_id', $product->getKey())
        ->selectRaw('count(unit_cost) as costed, sum(quantity) as units, coalesce(sum(quantity * unit_cost), 0) as value')
        ->first());

    $before = [$kilogramLedger($assam), $kilogramLedger($darjeeling)];

    $migration->up();

    $gramLedger = fn (Product $product): array => [
        'on hand' => Grams::toKilograms($this->ledger->onHand($product)),
        'value' => $this->ledger->valueAtCost($product),
        'average' => $this->ledger->averageCost($product),
    ];

    expect([$gramLedger($assam), $gramLedger($darjeeling)])->toBe($before)
        ->and($before[0])->toBe(['on hand' => '9', 'value' => 414999, 'average' => 46111])
        ->and($before[1])->toBe(['on hand' => '3', 'value' => null, 'average' => null])
        ->and($line->refresh()->unit_cost)->toBe(46111)
        ->and($line->quantity)->toBe(4)
        ->and(SupplyLine::query()->pluck('quantity')->all())->toBe([10000, 5000])
        ->and(StockCount::query()->orderBy('id')->pluck('quantity')->all())->toBe([9000, 3000])
        ->and($supply->refresh()->totalCost())->toBe(691665);

    $listed = $this->ledger->withValueAtCost($this->ledger->withOnHand(Product::query()->whereKey($assam->getKey())))->sole();

    expect((int) $listed->{StockLedger::ON_HAND})->toBe(9000)
        ->and($listed->{StockLedger::VALUE_AT_COST})->toBe(414999);
});

it('values grams at a cost per kilogram, rounding half away from zero once over the sum', function () {
    $product = Product::factory()->create();

    /** 1 g at ৳15.00/kg is 1.5 poisha, and 1 g at ৳14.99/kg is 1.499. */
    $this->ledger->recordSupply(Supplier::factory()->create(), today(), [
        ['product_id' => $product->getKey(), 'quantity' => 1, 'unit_cost' => 1500],
    ]);

    expect($this->ledger->valueAtCost($product))->toBe(2)
        ->and($this->ledger->averageCost($product))->toBe(1500);

    $this->ledger->recordSupply(Supplier::factory()->create(), today(), [
        ['product_id' => $product->getKey(), 'quantity' => 1, 'unit_cost' => 1499],
    ]);

    /** Σ = 1500 + 1499 = 2999 ÷ 1000 = 2.999, rounded once rather than 2 + 1 row by row. */
    $listed = $this->ledger->withValueAtCost(Product::query()->whereKey($product->getKey()))->sole();

    expect($this->ledger->valueAtCost($product))->toBe(3)
        ->and($listed->{StockLedger::VALUE_AT_COST})->toBe(3)
        ->and($this->ledger->averageCost($product))->toBe(1500);
});

it('casts the money it sums in a query to an integer type the connection\'s engine knows', function (string $connection, string $integer) {
    /** MySQL has no `cast(… as bigint)`; it is a syntax error there, and SQLite and PostgreSQL both accept it. Nothing here connects. */
    $database = DB::connection($connection);

    expect(Grams::summedCost($database))->toBe("cast(round(sum(quantity * unit_cost) / 1000.0) as {$integer})")
        ->and(Grams::lineCost($database))->toBe("cast(round(quantity * unit_cost / 1000.0) as {$integer})");
})->with([
    'MySQL' => ['mysql', 'signed'],
    'MariaDB' => ['mariadb', 'signed'],
    'PostgreSQL' => ['pgsql', 'bigint'],
    'SQLite' => ['sqlite', 'bigint'],
]);

it('keeps a weighted average per kilogram when deliveries are not whole kilograms', function () {
    $product = Product::factory()->create();

    $this->ledger->recordSupply(Supplier::factory()->create(), today(), [
        ['product_id' => $product->getKey(), 'quantity' => 2500, 'unit_cost' => 40000],
        ['product_id' => $product->getKey(), 'quantity' => 500, 'unit_cost' => 52000],
    ]);

    /** (2500 × 40000 + 500 × 52000) ÷ 3000 = 42000 a kilogram; worth 3 kg × ৳420. */
    expect($this->ledger->averageCost($product))->toBe(42000)
        ->and($this->ledger->valueAtCost($product))->toBe(126000)
        ->and($this->ledger->onHand($product))->toBe(3000);
});

it('draws grams as kilograms with decimals only where a part of one is held', function (int $grams, string $drawn) {
    expect(Grams::toKilograms($grams))->toBe($drawn);
})->with([
    'whole' => [12000, '12'],
    'half' => [2500, '2.5'],
    'one gram' => [1001, '1.001'],
    'thousands' => [1250250, '1,250.25'],
    'below nothing' => [-750, '-0.75'],
    'nothing' => [0, '0'],
]);

it('reads a typed kilogram figure as grams', function (int|float|string $typed, int $grams) {
    expect(Grams::fromKilograms($typed))->toBe($grams);
})->with([
    [12, 12000],
    ['2.5', 2500],
    ['0.001', 1],
    [0.3, 300],
]);
