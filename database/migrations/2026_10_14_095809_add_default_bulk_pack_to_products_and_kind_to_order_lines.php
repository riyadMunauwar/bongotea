<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Product sold পাইকারি marks one of its পাইকারি Packs as the default a
     * bulk buyer starts from (ADR-0036); null once that Pack is deleted.
     *
     * A bulk line now names its Pack and keeps its weight too, so `pack_grams`
     * no longer says which kind a line is. Each line carries its Order's kind,
     * which is what its weight is read by: a bulk line weighs its Kilograms
     * whatever Pack they come in, a retail line its Pack's weight × its count.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('default_bulk_pack_id')->nullable()->after('sold_retail')->constrained('packs')->nullOnDelete();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('kind')->default('bulk')->after('order_id');
        });

        DB::table('order_lines')
            ->whereIn('order_id', DB::table('orders')->where('kind', 'retail')->select('id'))
            ->update(['kind' => 'retail']);
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_bulk_pack_id');
        });
    }
};
