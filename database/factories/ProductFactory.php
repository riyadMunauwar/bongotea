<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published, Available Product: what the store sells unless a test says
 * otherwise.
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
            'status' => ProductStatus::Published,
            'is_available' => true,
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
}
