<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Grade and the Particle size become two Characteristics, and every
     * Product gains its own Storage tips.
     *
     * What `particle_size` held was always the Grade — লিফ, BOP, PD, ডাস্ট,
     * CD — so the column is renamed rather than copied, and the Particle size
     * starts blank beside it. Every Product, one taken out of the catalogue
     * included, is given the four common Storage tips, written out here as
     * they stood when this ran rather than read from the model.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('particle_size', 'grade');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('particle_size')->nullable()->after('grade');
            $table->json('storage_tips')->nullable()->after('business_tips');
        });

        DB::table('products')->update([
            'storage_tips' => json_encode([
                ['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => 'ঠান্ডা ও আর্দ্রতামুক্ত স্থানে রাখুন।'],
                ['heading' => 'বায়ুরোধী পাত্রে রাখুন', 'explanation' => 'প্যাকেট খোলার পর মুখ বন্ধ রাখুন।'],
                ['heading' => 'রোদ থেকে দূরে রাখুন', 'explanation' => 'সূর্যালোক ও অতিরিক্ত তাপ এড়িয়ে চলুন।'],
                ['heading' => 'তীব্র গন্ধ থেকে দূরে রাখুন', 'explanation' => 'মসলা, সাবান ও সুগন্ধি থেকে আলাদা রাখুন।'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['particle_size', 'storage_tips']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('grade', 'particle_size');
        });
    }
};
