<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stock ledger counts grams (ADR-0035): every quantity it holds is
     * multiplied by 1000.
     *
     * Costs are not touched, and that is what keeps every figure where it was.
     * A cost stays minor units per kilogram, so a movement's value is its
     * grams × its cost ÷ 1000 — the same money it carried as kilograms × cost —
     * and a weighted average over grams is the same cost per kilogram it was
     * over kilograms. Order Lines are not part of the ledger and keep their
     * kilograms and their stamped cost exactly as captured.
     *
     * The quantity columns widen to big integers first: a quantity times a
     * cost is computed in the column's own type on PostgreSQL, and a hundred
     * kilograms in grams times a cost per kilogram is past what a 32-bit
     * integer holds.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->bigInteger('quantity')->change();
        });

        Schema::table('supply_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->change();
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->change();
        });

        DB::table('stock_movements')->update(['quantity' => DB::raw('quantity * 1000')]);
        DB::table('supply_lines')->update(['quantity' => DB::raw('quantity * 1000')]);
        DB::table('stock_counts')->update(['quantity' => DB::raw('quantity * 1000')]);
    }

    /**
     * Back to whole kilograms. A quantity that is not a whole number of them
     * has no exact way back and is rounded to the nearest.
     */
    public function down(): void
    {
        DB::table('stock_movements')->update(['quantity' => DB::raw('round(quantity / 1000.0)')]);
        DB::table('supply_lines')->update(['quantity' => DB::raw('round(quantity / 1000.0)')]);
        DB::table('stock_counts')->update(['quantity' => DB::raw('round(quantity / 1000.0)')]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('supply_lines', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->change();
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->change();
        });
    }
};
