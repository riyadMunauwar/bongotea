<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one unit of this line cost, stamped at Capture beside the price it
     * sold at (ADR-0022).
     *
     * Additive on purpose: every Order captured before this column existed
     * carries null, and nothing is backfilled. A cost invented for a past
     * Order from today's average would be a margin figure nobody could trace
     * to a delivery, which is the failure the whole ledger exists to avoid.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            /**
             * Null is *unknown*, never nothing: a Product nobody has recorded
             * buying has no average to stamp, and a margin read off a zero
             * cost would present it as infinitely profitable. The same choice
             * On Hand makes against `0`.
             *
             * Signed, unlike the `unit_price` beside it, and the difference is
             * the point: a price is a figure a Merchant set and cannot be
             * below nothing, where this one is read off a ledger that is
             * allowed to go below nothing (ADR-0021). A Product oversold into
             * negative On Hand and then restocked cheaply really does average
             * out below zero, and an unsigned column would answer that by
             * refusing a Customer's Checkout. The ledger's own `unit_cost`
             * is signed for the same reason.
             */
            $table->bigInteger('unit_cost')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
