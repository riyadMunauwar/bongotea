<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a Courier was last asked about a parcel, and when nobody could
     * account for it any longer.
     *
     * Both are the sweep's bookkeeping and neither is a status: the Courier's
     * last word survives being asked again and survives being given up on
     * (ADR-0018), exactly as it survives being abandoned.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            /**
             * When this Courier last answered a question about this parcel —
             * answered, not was asked: a Courier that could not be reached
             * leaves this untouched, so the next sweep asks again rather than
             * waiting out an interval on the strength of a call that failed
             * (ADR-0019).
             *
             * Null is "never asked", which is every parcel the moment it is
             * booked, and is what makes a freshly booked parcel due at once.
             */
            $table->timestamp('polled_at')->nullable()->after('booked_at');

            /**
             * When this attempt had gone on so long without resolving that
             * somebody should look at it. Written once by the sweep and never
             * cleared: a parcel that was stuck for a fortnight and then
             * arrived was still stuck, and the Merchant's own courier is the
             * reason.
             *
             * A time and not a flag, for the reason `abandoned_at` is one —
             * "since when" is the whole of what an operator wants to know
             * about a parcel nobody can account for.
             */
            $table->timestamp('stuck_at')->nullable()->after('polled_at');

            /**
             * The one question the sweep asks: this Courier's parcels, least
             * recently asked about first. The re-ask interval is the driver's
             * own, so the sweep works one Courier at a time and the Courier
             * leads the index.
             */
            $table->index(['courier', 'polled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['courier', 'polled_at']);
            $table->dropColumn(['polled_at', 'stuck_at']);
        });
    }
};
