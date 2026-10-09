<?php

namespace Database\Factories;

use App\Enums\EnquiryStatus;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+88017'.fake()->numerify('########'),
            'topic' => 'দাম',
            'message' => fake()->sentence(),
        ];
    }

    /** Called back by the given User, or by a new one. */
    public function replied(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'status' => EnquiryStatus::Replied,
            'handled_by_id' => $user ?? User::factory(),
            'handled_at' => now(),
        ]);
    }

    public function closed(?User $user = null): static
    {
        return $this->replied($user)->state(['status' => EnquiryStatus::Closed]);
    }
}
