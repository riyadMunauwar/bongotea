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
            $table->json('checkout_config')->nullable()->after('body');
        });

        /**
         * Backfilled to the form each Page already had, so a Merchant who never
         * opens the Checkout settings sees no change at all.
         *
         * Spelled out rather than read from CheckoutConfig, like every other
         * migration here: what a migration wrote on the day it ran must not
         * change meaning afterwards. A row in this shape keeps reading
         * correctly as the shape grows, because reading a Config fills in
         * whatever it does not find.
         */
        DB::table('pages')->update([
            'checkout_config' => json_encode(['fields' => [
                ['key' => 'name', 'label' => 'Your name', 'required' => true],
                ['key' => 'phone', 'label' => 'Phone number', 'required' => true],
                ['key' => 'district', 'label' => 'District', 'required' => true],
                ['key' => 'address', 'label' => 'Delivery address', 'required' => true],
            ]]),
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
