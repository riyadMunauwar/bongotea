<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of its Product's Tiers a line was priced from, as the range stood
     * then (ADR-0031), and the Price Override a User set on it instead: the
     * reason and who gave it. A line under an override records no Tier; its
     * reason says why instead. Lines priced before this keep neither.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedInteger('tier_min')->nullable()->after('quantity');
            $table->unsignedInteger('tier_max')->nullable()->after('tier_min');
            $table->text('price_override_reason')->nullable()->after('tier_max');
            $table->foreignId('price_overridden_by')->nullable()->after('price_override_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_overridden_by');
            $table->dropColumn(['tier_min', 'tier_max', 'price_override_reason']);
        });
    }
};
