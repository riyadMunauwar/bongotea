<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods arriving from a Supplier, as one document: who they came from,
     * the day they came and the reference they can be found under.
     *
     * The header of an `Order` read from the buying side, with a line per
     * Product under it. What arrived is on the lines, and what the ledger did
     * about it is on the movements each line caused — this row carries no
     * quantity and no total, because a figure held apart from the rows it is
     * the sum of is a figure that can disagree with them.
     *
     * Never updated or deleted; `Supply` refuses both at the model. A Supply
     * recorded wrongly is Voided, which writes the reversal and leaves the
     * pair on the record.
     */
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();

            /**
             * Restricted rather than cascading: a Supplier's deletion is
             * soft, and force-deleting one who ever delivered anything would
             * take the buying history with them.
             */
            $table->foreignId('supplier_id')->index()->constrained()->restrictOnDelete();

            /**
             * The day the goods came, which is the day the buyer will look
             * for and not necessarily the day somebody got round to typing
             * it in. A date rather than a timestamp: nobody knows the hour a
             * van arrived, and asking for one invents precision.
             */
            $table->date('supplied_on');

            /** The supplier's own invoice or challan number, where there is one to quote. */
            $table->string('reference')->nullable();

            /**
             * Who recorded it, so a surprising figure has somebody to ask
             * about it. Null where the User has since been deleted: the
             * Supply outlives the account.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplies');
    }
};
