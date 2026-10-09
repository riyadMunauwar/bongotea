<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each Product owns its Tiers (ADR-0031): an ordered list of
     * `{min, max|null, price}` with the price in minor units, replacing the
     * discount kind and the Tier Discounts keyed by a store-wide Tier. `price`
     * stays as the Regular Price — the first Tier's — so a Product that had
     * only that becomes one flat Tier at it.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('tiers')->nullable()->after('price');
        });

        DB::table('products')->orderBy('id')->each(function (object $product): void {
            DB::table('products')->where('id', $product->id)->update([
                'tiers' => json_encode([['min' => 1, 'max' => null, 'price' => (int) $product->price]]),
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'tier_discounts']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('discount_type')->default('percent')->after('price');
            $table->json('tier_discounts')->nullable()->after('discount_type');
            $table->dropColumn('tiers');
        });
    }
};
