<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            /**
             * Nullable for the length of this migration only. Every read of a
             * Config is meant to have one shape, so the column is filled and
             * then closed rather than leaving a null check on every read of
             * every Page forever.
             */
            $table->json('order_complete_config')->nullable()->after('checkout_config');
        });

        /**
         * Backfilled to the page every Page already drew: Centered classic,
         * with every word the built-in one, so a Merchant who never opens the
         * Order complete tab sees no change on a live campaign.
         *
         * Spelled out rather than read from OrderCompleteConfig, like every
         * other migration here: what a migration wrote on the day it ran must
         * not change meaning afterwards. A row in this shape keeps reading
         * correctly as the shape grows, because reading a Config fills in
         * whatever it does not find.
         */
        DB::table('pages')->update([
            'order_complete_config' => json_encode(['layout' => 'centered_classic']),
        ]);

        Schema::table('pages', function (Blueprint $table) {
            $table->json('order_complete_config')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('order_complete_config');
        });
    }
};
