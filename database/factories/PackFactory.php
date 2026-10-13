<?php

namespace Database\Factories;

use App\Models\Pack;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An Available 250 g Pack of a published, Available Product, at its MRP.
 *
 * @extends Factory<Pack>
 */
class PackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'grams' => 250,

            /** Minor units: 175.00 is stored as 17500. */
            'price' => fake()->numberBetween(5, 50) * 1000,
            'mrp' => fn (array $attributes): int => $attributes['price'],
            'is_available' => true,
        ];
    }

    /** Switched off — the pouches ran out — while its Product sells on. */
    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }
}
