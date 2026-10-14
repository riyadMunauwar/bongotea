<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why an Order was let past Free to Sell, and the User who gave the
     * reason (ADR-0037). Both null on an Order nobody sold beyond stock.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('sold_beyond_stock_reason')->nullable()->after('note');
            $table->foreignId('sold_beyond_stock_by')->nullable()->after('sold_beyond_stock_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sold_beyond_stock_by');
            $table->dropColumn('sold_beyond_stock_reason');
        });
    }
};
