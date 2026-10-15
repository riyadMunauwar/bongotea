<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each kind's switch on a Product, as stored. A copy, not a reference to
     * app code, so this migration reads the same whatever later becomes of it.
     *
     * @var array<string, string>
     */
    private const SOLD = ['bulk' => 'sold_bulk', 'retail' => 'sold_retail'];

    /**
     * The bulk order page listed every tea sold পাইকারি, and the packet basket
     * every Pack sold খুচরা. Their starting Carts now lay out only what is
     * assigned to them (ADR-0038), so a starting Cart nobody has assigned
     * anything to yet is given every Product sold its way, and the Page reads
     * as it did. One the Merchant has already assigned to is left alone.
     */
    public function up(): void
    {
        foreach (self::SOLD as $kind => $column) {
            $cartId = DB::table('carts')->where('starting_kind', $kind)->value('id');

            if ($cartId === null || DB::table('cart_product')->where('cart_id', $cartId)->exists()) {
                continue;
            }

            $productIds = DB::table('products')->whereNull('deleted_at')->where($column, true)->orderBy('id')->pluck('id');

            DB::table('cart_product')->insert($productIds->map(fn (int $productId): array => ['cart_id' => $cartId, 'product_id' => $productId])->all());
        }
    }

    /**
     * Left as it is: an assignment cannot be told from one the Merchant made.
     */
    public function down(): void {}
};
