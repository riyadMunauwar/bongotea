<?php

namespace Database\Factories;

use App\Enums\OrderKind;
use App\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => OrderKind::Bulk,
            'is_switched_on' => true,
            'sort_order' => 0,
        ];
    }

    public function bulk(): static
    {
        return $this->state(['kind' => OrderKind::Bulk]);
    }

    public function retail(): static
    {
        return $this->state(['kind' => OrderKind::Retail]);
    }

    public function switchedOff(): static
    {
        return $this->state(['is_switched_on' => false]);
    }
}
