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
             * Nullable for the length of this migration only, like the two
             * Configs before it: the column is filled and then closed, so no
             * read of a Page anywhere has to ask whether it has one.
             */
            $table->json('tracking_config')->nullable()->after('order_complete_config');
        });

        /**
         * Backfilled to what every Page already did: report to whichever
         * Destination is default for its Provider, fire every event the
         * Provider declares, and send the whole basket with each one.
         *
         * Spelled out rather than read from TrackingConfig, like every other
         * migration here: what a migration wrote on the day it ran must not
         * change meaning afterwards. A row in this shape keeps reading
         * correctly as the shape grows, because reading a Config fills in
         * whatever it does not find.
         */
        DB::table('pages')->update([
            'tracking_config' => json_encode([
                'destination_id' => null,
                'events' => [],
                'suppress_content_detail' => false,
            ]),
        ]);

        Schema::table('pages', function (Blueprint $table) {
            $table->json('tracking_config')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('tracking_config');
        });
    }
};
