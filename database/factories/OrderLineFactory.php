<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory();

        return [
            'order_id' => Order::factory(),
            'product_id' => $product,

            /**
             * Copied from the Product at build time, the way Capture copies
             * them — a line that read them back through the relation would not
             * be exercising the snapshot at all.
             */
            'product_name' => fn (array $attributes): string => Product::find($attributes['product_id'])->name,
            'unit_price' => fn (array $attributes): int => Product::find($attributes['product_id'])->price,
            'quantity' => 1,
        ];
    }
}
