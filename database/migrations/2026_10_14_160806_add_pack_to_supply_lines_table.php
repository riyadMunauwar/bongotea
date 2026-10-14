<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Pack a Supply line came in, what it weighed and how many packets
     * it was, as the challan said them. All null on a loose delivery. The
     * weight is recorded beside the reference so the line still reads as it
     * was entered after its Pack is gone; `quantity` stays grams and
     * `unit_cost` stays per kilogram (ADR-0035).
     */
    public function up(): void
    {
        Schema::table('supply_lines', function (Blueprint $table) {
            $table->foreignId('pack_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('pack_grams')->nullable()->after('pack_id');
            $table->unsignedInteger('packets')->nullable()->after('pack_grams');
        });
    }

    public function down(): void
    {
        Schema::table('supply_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pack_id');
            $table->dropColumn(['pack_grams', 'packets']);
        });
    }
};
