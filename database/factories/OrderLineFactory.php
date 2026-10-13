<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
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
            'category_id' => fn (array $attributes): ?int => Product::withTrashed()->find($attributes['product_id'])?->category_id,
            'product_name' => fn (array $attributes): string => Product::find($attributes['product_id'])->name,
            'unit_price' => fn (array $attributes): int => Product::find($attributes['product_id'])->price,
            'quantity' => 1,
        ];
    }

    /** One Pack's worth, copied from the Pack the way retail Capture copies it. */
    public function ofPack(Pack $pack, int $count = 1): static
    {
        return $this->state(fn (): array => [
            'product_id' => $pack->product_id,
            'category_id' => $pack->product->category_id,
            'product_name' => $pack->product->name,
            'pack_id' => $pack->id,
            'pack_grams' => $pack->grams,
            'unit_price' => $pack->price,
            'mrp' => $pack->mrp,
            'quantity' => $count,
        ]);
    }
}
