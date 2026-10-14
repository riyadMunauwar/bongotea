<?php

namespace Database\Factories;

use App\Models\Pack;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An Available 250 g Pack of a published, Available Product, serving
 * পাইকারি and খুচরা both, at its MRP.
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
            'serves_bulk' => true,
            'serves_retail' => true,

            /** Minor units: 175.00 is stored as 17500. */
            'price' => fake()->numberBetween(5, 50) * 1000,
            'mrp' => fn (array $attributes): ?int => $attributes['price'],
            'is_available' => true,
        ];
    }

    /** Switched off — the pouches ran out — while its Product sells on. */
    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }

    /** Serving পাইকারি alone, so it needs no price and the খুচরা Cart never offers it. */
    public function bulkOnly(): static
    {
        return $this->state(['serves_bulk' => true, 'serves_retail' => false, 'price' => null, 'mrp' => null]);
    }

    /** Serving খুচরা alone: never a size a bulk line is supplied in. */
    public function retailOnly(): static
    {
        return $this->state(['serves_bulk' => false, 'serves_retail' => true]);
    }

    /** Serving পাইকারি and খুচরা both, said outright where a test turns on it. */
    public function bothWays(): static
    {
        return $this->state(['serves_bulk' => true, 'serves_retail' => true]);
    }
}
