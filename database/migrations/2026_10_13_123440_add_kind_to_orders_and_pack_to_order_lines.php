<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An Order's kind — bulk or retail, settled at Capture (ADR-0034) — with
     * every Order captured before it bulk, which is all there was.
     *
     * A retail line names its Pack and keeps the Pack's weight as it stood at
     * Capture, so a Pack deleted later leaves the line whole: the reference
     * goes null and the weight stays. Both are null on a bulk line.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('kind')->default('bulk')->after('order_number')->index();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('pack_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('pack_grams')->nullable()->after('pack_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pack_id');
            $table->dropColumn('pack_grams');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
