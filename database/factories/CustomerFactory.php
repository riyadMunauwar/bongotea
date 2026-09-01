<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /** Canonical form, as every write path stores it. */
            'phone' => PhoneNumber::normalise('01'.fake()->unique()->numerify('#########')),
        ];
    }
}
