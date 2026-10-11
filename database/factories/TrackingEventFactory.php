<?php

namespace Database\Factories;

use App\Enums\TrackingEventState;
use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\Order;
use App\Models\TrackingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingEvent>
 */
class TrackingEventFactory extends Factory
{
    /**
     * A Pending `Purchase` for a captured Order, the shape every row starts
     * in. The deduplication id is the order number, as the recorder writes
     * it, so a row made here deduplicates against a browser copy exactly as
     * a real one would.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => TrackingProvider::Facebook,
            'moment' => TrackingMoment::Capture,
            'event' => 'Purchase',
            'order_id' => Order::factory(),
            'deduplication_id' => fn (array $attributes): string => Order::find($attributes['order_id'])->order_number,
            'occurred_at' => now(),
            'source_url' => 'https://shop.test/checkout',
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
            'state' => TrackingEventState::Pending,
        ];
    }
}
