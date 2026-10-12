<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Five more fixed Characteristics, a Product's own custom ones, and the
     * brewing guide as steps rather than one paragraph.
     *
     * Strength moves from one-to-five to the rank of its grade (App\Enums\Strength):
     * 5, 4 and 3 become ৫, ৪ and ৩, and the 1s and 2s nobody used become ২+,
     * the mildest grade there is. A guide already written becomes one step per
     * line.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tea_type')->nullable()->after('strength');
            $table->string('particle_size')->nullable()->after('tea_type');
            $table->string('liquor_body')->nullable()->after('liquor_colour');
            $table->string('infusion')->nullable()->after('aroma');
            $table->string('package_weight')->nullable()->after('cups_per_kilogram');
            $table->json('custom_characteristics')->nullable()->after('package_weight');
            $table->json('brewing_steps')->nullable()->after('not_best_for');
        });

        foreach ([5 => 9, 4 => 6, 2 => 1] as $from => $rank) {
            DB::table('products')->where('strength', $from)->update(['strength' => $rank]);
        }

        DB::table('products')->whereNotNull('brewing_guide')->eachById(function (object $product): void {
            $steps = array_values(array_filter(array_map('trim', preg_split('/\R/u', $product->brewing_guide))));

            DB::table('products')->where('id', $product->id)->update([
                'brewing_steps' => $steps === [] ? null : json_encode($steps, JSON_UNESCAPED_UNICODE),
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('brewing_guide');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('brewing_guide')->nullable()->after('not_best_for');
        });

        DB::table('products')->whereNotNull('brewing_steps')->eachById(function (object $product): void {
            $steps = json_decode($product->brewing_steps, true);

            DB::table('products')->where('id', $product->id)->update([
                'brewing_guide' => is_array($steps) ? implode("\n", $steps) : null,
            ]);
        });

        DB::table('products')->whereNotNull('strength')->update([
            'strength' => DB::raw('case when strength >= 8 then 5 when strength >= 5 then 4 when strength >= 2 then 3 else 2 end'),
        ]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tea_type', 'particle_size', 'liquor_body', 'infusion', 'package_weight', 'custom_characteristics', 'brewing_steps']);
        });
    }
};
