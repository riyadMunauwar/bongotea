<?php

use App\Models\Product;
use App\Models\StockCount;
use Illuminate\Support\Facades\DB;

/**
 * The fixed list of causes carried into the Merchant's own (ADR-0040),
 * without a past Adjustment changing its words.
 */
it('carries every past count onto the cause it was saved under, or onto the fact it records', function () {
    $migration = require database_path('migrations/2026_10_18_104330_carry_adjustment_causes_into_a_list.php');

    /** Back to the fixed list, and counts written the way it wrote them. */
    $migration->down();

    $product = Product::factory()->create();

    $count = function (string $cause, int $difference) use ($product): int {
        $id = DB::table('stock_counts')->insertGetId([
            'product_id' => $product->getKey(),
            'quantity' => 1000,
            'cause' => $cause,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_movements')->insert([
            'product_id' => $product->getKey(),
            'quantity' => $difference,
            'reason' => 'adjustment',
            'source_type' => (new StockCount)->getMorphClass(),
            'source_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    };

    $opening = $count('opening_count', 1000);
    $damaged = $count('damaged', -200);
    $agreed = $count('miscount', 0);

    $migration->up();

    expect(StockCount::find($opening))->is_opening->toBeTrue()->adjustmentCause->toBeNull()
        ->and(StockCount::find($damaged)->adjustmentCause->name)->toBe('Damaged')
        /** It explained nothing, so it reads as Checked now. */
        ->and(StockCount::find($agreed)->adjustmentCause)->toBeNull()
        ->and(StockCount::find($agreed)->is_opening)->toBeFalse();
});
