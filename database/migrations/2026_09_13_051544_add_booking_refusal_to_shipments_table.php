<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The third arm of Active, which had no column until now: a Shipment a
     * Courier refused at booking.
     *
     * A booking made while a User waits records nothing when it is refused
     * (ADR-0020), so no such row could exist. A bulk booking writes every row
     * before anything is dispatched — which is the whole of what makes a
     * batch interrupted halfway recoverable — so a parcel the Courier then
     * says no to leaves a row behind, and that row must not go on holding the
     * Order down as the one parcel out for it.
     *
     * A time and a reason, shaped like `abandoned_at` and for the same
     * reason: the row keeps saying what state it reached and who said what
     * about it, rather than having its account overwritten by the refusal.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('refused_at')->nullable()->after('booked_at');

            /** The Courier's own words, kept verbatim: an operator reads this and acts on it. */
            $table->text('refusal_reason')->nullable()->after('refused_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['refused_at', 'refusal_reason']);
        });
    }
};
