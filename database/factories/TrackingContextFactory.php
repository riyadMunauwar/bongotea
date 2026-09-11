<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\TrackingContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingContext>
 */
class TrackingContextFactory extends Factory
{
    /**
     * What an ad click that placed an Order left behind: the Page it landed
     * on, the browser it arrived in, and Facebook's two identifiers in the
     * shape Meta itself writes them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'source_url' => 'https://shop.test/promo',
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
            'click_ids' => [
                'fbc' => 'fb.1.1757000000000.IwAR0abcdefghijklmnop',
                'fbp' => 'fb.1.1757000000000.1098765432',
            ],
        ];
    }

    /**
     * A visitor whose browser ran no script: everything the server could see
     * for itself, and no click identifiers at all.
     */
    public function withoutClickIds(): self
    {
        return $this->state(['click_ids' => []]);
    }
}
