<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplyLine>
 */
class SupplyLineFactory extends Factory
{
    /**
     * One Product's worth of a delivery, in minor units like every other
     * money figure in the system.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supply_id' => Supply::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 100) * 1000,
            'unit_cost' => fake()->numberBetween(1, 500) * 100,
        ];
    }
}
