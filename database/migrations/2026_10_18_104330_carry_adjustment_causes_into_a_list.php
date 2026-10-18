<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fixed list of causes becomes the Merchant's own (ADR-0040).
     *
     * Seeded with the four causes every existing Count already carries, plus
     * the two a tea shelf loses most to, so nothing on the record changes
     * words. Opening count is not a cause any more: it is `is_opening` on the
     * Count, a fact the ledger saw rather than a reason somebody picked.
     */
    public function up(): void
    {
        Schema::create('adjustment_causes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('direction');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach ([
            ['Damaged', 'removes'],
            ['Lost', 'removes'],
            ['Found', 'adds'],
            ['Miscount', 'either'],
            ['Sample / tasting', 'removes'],
            ['Personal use', 'removes'],
        ] as [$name, $direction]) {
            DB::table('adjustment_causes')->insert([
                'name' => $name,
                'direction' => $direction,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->foreignId('adjustment_cause_id')->nullable()->after('quantity')->constrained()->restrictOnDelete();
            $table->boolean('is_opening')->default(false)->after('adjustment_cause_id');
        });

        foreach (['damaged' => 'Damaged', 'lost' => 'Lost', 'found' => 'Found', 'miscount' => 'Miscount'] as $cause => $name) {
            DB::table('stock_counts')->where('cause', $cause)->update([
                'adjustment_cause_id' => DB::table('adjustment_causes')->where('name', $name)->value('id'),
            ]);
        }

        DB::table('stock_counts')->where('cause', 'opening_count')->update(['is_opening' => true]);

        /** A count that agreed explained nothing, whatever cause it was saved under: it reads as Checked now. */
        DB::table('stock_counts')
            ->whereIn('id', DB::table('stock_movements')
                ->where('source_type', 'App\Models\StockCount')
                ->where('quantity', 0)
                ->select('source_id'))
            ->update(['adjustment_cause_id' => null]);

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropColumn('cause');
        });
    }

    /**
     * Back to the fixed list. A cause the Merchant added has no word on it and
     * is carried back as Miscount, the one that was honestly either way.
     */
    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->string('cause')->default('miscount')->after('quantity');
        });

        foreach (['Damaged' => 'damaged', 'Lost' => 'lost', 'Found' => 'found'] as $name => $cause) {
            DB::table('stock_counts')
                ->where('adjustment_cause_id', DB::table('adjustment_causes')->where('name', $name)->value('id'))
                ->update(['cause' => $cause]);
        }

        DB::table('stock_counts')->where('is_opening', true)->update(['cause' => 'opening_count']);

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_cause_id');
            $table->dropColumn('is_opening');
        });

        Schema::dropIfExists('adjustment_causes');
    }
};
