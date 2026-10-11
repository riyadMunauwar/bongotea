<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An Order's weight, kept beside its money as the sum of its lines'
     * Kilograms, and each line's MRP as it stood when it was priced, so the
     * Saving the buyer was shown can still be read after the Product's MRP
     * moves.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('total_kilograms')->default(0)->after('subtotal');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('mrp')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('mrp');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_kilograms');
        });
    }
};
