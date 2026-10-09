<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An Order's weight, kept beside its money as the sum of its lines'
     * Kilograms, and each line's Regular Price as it stood when it was
     * priced, so what a Tier saved the buyer can still be read after the
     * Product's Tiers move. Lines priced before this keep no Regular Price.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('total_kilograms')->default(0)->after('subtotal');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('regular_price')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('regular_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_kilograms');
        });
    }
};
