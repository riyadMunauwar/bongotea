<?php

namespace Database\Factories;

use App\Enums\AdjustmentCause;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    /**
     * Somebody's first look at a shelf, which is what most counts in a young
     * installation are.
     *
     * A count made this way writes no Adjustment: `StockLedger::count()` is
     * what records one for real, and a test about the ledger must go through
     * it rather than around it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(0, 200) * 1000,
            'cause' => AdjustmentCause::OpeningCount,
            'note' => null,
            'user_id' => User::factory(),
        ];
    }
}
