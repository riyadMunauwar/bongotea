<?php

use App\Support\Checkout\CheckoutConfig;
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
            $table->json('checkout_config')->nullable()->after('body');
        });

        /**
         * Backfilled to the form each Page already had: name, phone, district
         * and address, in that order, all required. A Merchant who never opens
         * the Checkout settings sees no change at all.
         *
         * Written from the class rather than a frozen literal. The two cannot
         * drift into disagreeing about anything that matters: an installation
         * runs this once against the rows it has, and a fresh database reaches
         * it with no rows to fill.
         */
        DB::table('pages')->update([
            'checkout_config' => json_encode(CheckoutConfig::default()->toArray()),
        ]);

        Schema::table('pages', function (Blueprint $table) {
            $table->json('checkout_config')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('checkout_config');
        });
    }
};
