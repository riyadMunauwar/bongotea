<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the Courier was told about the parcel itself, as it was told it.
     *
     * The recipient columns already snapshot who the parcel was going to; this
     * snapshots the rest of what went out — the delivery type, what is inside,
     * a weight, a pickup store — which differs from Courier to Courier and is
     * therefore a document rather than a column each. Every key in it is a
     * `ParcelField` a driver declared, so a fourth Courier's own questions
     * arrive with its driver and no migration follows them.
     *
     * Recorded rather than read back off Settings at the moment it is needed:
     * a Merchant who changes their default delivery type between two attempts
     * must still be able to see what the first attempt actually carried, and a
     * dialog that overrode a default for one parcel leaves no trace anywhere
     * else.
     *
     * Nullable for the rows written before it existed, and for a Courier that
     * asks nothing beyond the recipient.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->json('parcel_details')->nullable()->after('recipient_note');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('parcel_details');
        });
    }
};
