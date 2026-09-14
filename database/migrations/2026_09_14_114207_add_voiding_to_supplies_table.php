<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is written on a Supply that should never have been written down:
     * the moment somebody said so, and who said it.
     *
     * The only two columns on this table that are ever written after the
     * insert, and `Supply` refuses every other update at the model. Voiding
     * is not a deletion and not an edit — the document stays exactly as it
     * was recorded, these two fields say it does not count, and the
     * reversing movements say so to the ledger.
     */
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            /** Null is the ordinary state: a delivery that stands. */
            $table->timestamp('voided_at')->nullable()->after('user_id');

            /**
             * Who voided it, named the way `shipments.abandoned_by` is,
             * because the User who recorded the delivery already holds
             * `user_id` and the two are rarely the same person — the whole
             * value of a void is that it carries a second name.
             *
             * Null where that account has since been deleted: the void
             * outlives it, exactly as the Supply outlives the buyer's.
             */
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn('voided_at');
        });
    }
};
