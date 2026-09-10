<?php

namespace Database\Factories;

use App\Enums\TrackingProvider;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
{
    /**
     * A usable Facebook Destination, switched on with its credentials filled
     * — the shape a Merchant's first Destination has once they have pasted
     * what Events Manager gave them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->company(),
            'provider' => TrackingProvider::Facebook,
            'dataset' => (string) fake()->numerify('###############'),
            'access_token' => 'EAA'.fake()->regexify('[A-Za-z0-9]{40}'),
            'enabled' => true,
        ];
    }

    /**
     * Switched off: the campaign break, where nothing is reported and nothing
     * accumulates.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    /**
     * On, but with nothing to authorise the call: the Merchant who has not
     * finished. Rows still accumulate for it, and the sweep says why it
     * skipped them.
     */
    public function withoutCredentials(): static
    {
        return $this->state(fn (): array => ['access_token' => null]);
    }

    /**
     * Not the Provider's default, for a test about a second Destination.
     * The observer makes the first one default whatever this says.
     */
    public function notDefault(): static
    {
        return $this->state(fn (): array => ['is_default' => false]);
    }
}
