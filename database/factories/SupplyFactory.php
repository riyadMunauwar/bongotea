<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supply>
 */
class SupplyFactory extends Factory
{
    /**
     * A delivery header with no lines and no movements behind it.
     *
     * Scenery for a screen, the way `StockMovementFactory` is. Anything
     * asserting what recording a Supply *does* goes through
     * `StockLedger::recordSupply()` instead — a document made here raised
     * nobody's stock.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'supplied_on' => fake()->dateTimeBetween('-2 months')->format('Y-m-d'),
            'reference' => fake()->bothify('INV-####'),
            'user_id' => User::factory(),
        ];
    }
}
