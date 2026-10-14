<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each Product is switched পাইকারি, খুচরা or both, and each Pack ticked
     * for the ways it serves (ADR-0036). A Product sold only খুচরা has no
     * Tiers, so no first-Tier price and no MRP per kilogram; a Pack serving
     * only পাইকারি has no MRP or Pack Price. The store has not gone live, so
     * nothing is carried over.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sold_bulk')->default(true)->after('is_available');
            $table->boolean('sold_retail')->default(false)->after('sold_bulk');
            $table->unsignedBigInteger('price')->nullable()->change();
            $table->unsignedBigInteger('mrp')->nullable()->change();
        });

        Schema::table('packs', function (Blueprint $table) {
            $table->boolean('serves_bulk')->default(false)->after('grams');
            $table->boolean('serves_retail')->default(false)->after('serves_bulk');
            $table->unsignedBigInteger('mrp')->nullable()->change();
            $table->unsignedBigInteger('price')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('packs', function (Blueprint $table) {
            $table->dropColumn(['serves_bulk', 'serves_retail']);
            $table->unsignedBigInteger('mrp')->nullable(false)->change();
            $table->unsignedBigInteger('price')->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sold_bulk', 'sold_retail']);
            $table->unsignedBigInteger('price')->nullable(false)->change();
            $table->unsignedBigInteger('mrp')->nullable(false)->change();
        });
    }
};
