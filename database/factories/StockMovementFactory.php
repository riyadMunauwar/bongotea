<?php

namespace Database\Factories;

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * An Adjustment caused by a Stock Count, which is the only movement
     * anything in this system writes today.
     *
     * Here so that a screen reading the ledger can be tested against rows
     * without going through the ledger to arrange them. Anything asserting
     * what the ledger *does* goes through `StockLedger` instead — a row made
     * here is scenery, not a movement the system decided on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 50),
            'reason' => StockMovementReason::Adjustment,
            'unit_cost' => null,
            'user_id' => User::factory(),
            'source_type' => (new StockCount)->getMorphClass(),

            /** A count of the same Product, so the scenery holds together if anybody follows the row back to its cause. */
            'source_id' => fn (array $attributes): int => StockCount::factory()
                ->create(['product_id' => $attributes['product_id']])
                ->getKey(),
        ];
    }
}
