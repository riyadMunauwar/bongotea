<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $page = Page::factory();

        return [
            'customer_id' => Customer::factory(),
            'page_id' => $page,
            'page_slug' => fn (array $attributes): string => Page::find($attributes['page_id'])?->slug ?? 'promo',
            'customer_name' => fake()->name(),
            'customer_phone' => fn (array $attributes): string => Customer::find($attributes['customer_id'])->phone,
            'customer_address' => fake()->streetAddress(),
            'customer_district' => fake()->randomElement(config('giopages.districts')),
            'delivery_label' => 'Delivery',
            'delivery_charge' => 6000,
            'status' => OrderStatus::New,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Cancelled]);
    }
}
