<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Product's Order Limits: the least and the most of it one Order may
     * hold, for পাইকারি in whole kilograms and for খুচরা in grams. Null is no
     * limit at that end.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('bulk_min_kilograms')->nullable()->after('default_bulk_pack_id');
            $table->unsignedInteger('bulk_max_kilograms')->nullable()->after('bulk_min_kilograms');
            $table->unsignedInteger('retail_min_grams')->nullable()->after('bulk_max_kilograms');
            $table->unsignedInteger('retail_max_grams')->nullable()->after('retail_min_grams');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['bulk_min_kilograms', 'bulk_max_kilograms', 'retail_min_grams', 'retail_max_grams']);
        });
    }
};
