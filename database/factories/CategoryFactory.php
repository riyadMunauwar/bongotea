<?php

namespace Database\Factories;

use App\Enums\CategoryTheme;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1, 99999))->toString(),
            'note' => fake()->sentence(3),
            'quality_headline' => fake()->sentence(4),
            'about' => fake()->paragraph(),
            'why_points' => [fake()->sentence(3), fake()->sentence(3), fake()->sentence(3)],
            'theme_key' => fake()->randomElement(CategoryTheme::cases())->value,
            'sort_order' => 0,
        ];
    }
}
