<?php

namespace Database\Factories;

use App\Enums\AdjustmentDirection;
use App\Models\AdjustmentCause;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdjustmentCause>
 */
class AdjustmentCauseFactory extends Factory
{
    /**
     * A cause of the Merchant's own beside the seeded six, which the migration
     * puts in every installation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'direction' => AdjustmentDirection::Either,
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['retired_at' => now()]);
    }
}
