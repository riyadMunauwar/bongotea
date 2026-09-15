<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is written on a Partly Delivered parcel once somebody has said
     * which of its items came back: the moment they said so, and who.
     *
     * The one thing a Courier cannot tell us. It reports a collected amount
     * and never a list of items, so a parcel that half arrived moves no stock
     * on its own and waits on a person (ADR-0021) — and the movements that
     * answer it cannot say by themselves that the question was answered,
     * because "everything stayed with the Customer" is a real answer that
     * writes nothing at all.
     *
     * A time and a name, exactly as Abandoned is and for the same reason
     * (ADR-0018): the Courier's own last word about the parcel stays standing
     * over it, and what this adds is the decision a User made underneath it.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            /** Null is the open question: a parcel whose shortfall nobody has accounted for. */
            $table->timestamp('answered_at')->nullable()->after('abandoned_by');

            /**
             * Who answered, named the way `abandoned_by` is. Null where that
             * account has since been deleted: the answer outlives it, exactly
             * as the parcel outlives whoever wrote it off.
             */
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn('answered_at');
        });
    }
};
