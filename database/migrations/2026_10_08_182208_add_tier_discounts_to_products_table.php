<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Product's Tier Discounts (ADR-0028): one discount kind for the whole
     * Product, and a discount per Tier keyed by the Tier's key in the Pricing
     * settings — a percentage, or minor units per Kilogram. `price` is the
     * Regular Price every Tier's discount is taken off.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('discount_type')->default('percent')->after('price');
            $table->json('tier_discounts')->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'tier_discounts']);
        });
    }
};
