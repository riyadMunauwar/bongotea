<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Pack;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published, Available Product sold পাইকারি and খুচরা both: what the store
 * sells unless a test says otherwise. A Product sold পাইকারি comes with one
 * Pack, a 1 kg bag serving পাইকারি alone and marked its default, so any whole
 * number of Kilograms can be ordered; a test that needs a Pack sold খুচরা, or
 * another bulk size, gives it one.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** The weight of the default পাইকারি Pack every factory Product sold পাইকারি is given. */
    public const DEFAULT_BULK_PACK_GRAMS = 1000;

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            if (! $product->sold_bulk || $product->default_bulk_pack_id !== null) {
                return;
            }

            $pack = Pack::factory()->bulkOnly()->for($product)->create(['grams' => self::DEFAULT_BULK_PACK_GRAMS]);

            $product->update(['default_bulk_pack_id' => $pack->getKey()]);
        });
    }

    /** No Pack at all, as a Product made outside its form may be. */
    public function withoutPacks(): static
    {
        return $this->afterCreating(function (Product $product): void {
            $product->update(['default_bulk_pack_id' => null]);
            $product->packs()->delete();
        });
    }

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
