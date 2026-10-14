<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published, Available Product sold পাইকারি and খুচরা both: what the store
 * sells unless a test says otherwise. It comes with no Packs; a test that
 * needs one gives it one.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1, 999999))->toString(),
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'short_description' => fake()->sentence(),

            /** Minor units: 990.00 is stored as 99000. A flat Product's single Tier, written by the model. */
            'price' => fake()->numberBetween(10, 500) * 10000,

            /** At the first Tier's price, so it saves nothing unless a test says otherwise. */
            'mrp' => fn (array $attributes): ?int => $attributes['tiers'][0]['price'] ?? $attributes['price'],
            'status' => ProductStatus::Published,
            'is_available' => true,
            'sold_bulk' => true,
            'sold_retail' => true,
        ];
    }

    /** Never yet gone live, so its slug can still be corrected. */
    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }

    /** Still shown, greyed, but cannot be ordered for now. */
    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }

    /** Sold পাইকারি alone: by the Kilogram, at its Tiers, and never in the খুচরা Cart. */
    public function bulkOnly(): static
    {
        return $this->state(['sold_bulk' => true, 'sold_retail' => false]);
    }

    /** Sold খুচরা alone: in Packs only, with no Tiers, and on no bulk surface. */
    public function retailOnly(): static
    {
        return $this->state(['sold_bulk' => false, 'sold_retail' => true, 'price' => null, 'mrp' => null, 'tiers' => null]);
    }

    /** Sold পাইকারি and খুচরা both, said outright where a test turns on it. */
    public function bothWays(): static
    {
        return $this->state(['sold_bulk' => true, 'sold_retail' => true]);
    }
}
