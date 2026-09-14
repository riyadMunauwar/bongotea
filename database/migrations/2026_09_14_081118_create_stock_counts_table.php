<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per thing a User physically counted, in the number they were
     * holding in their head at the shelf.
     *
     * What the ledger was out by is not here: that is worked out against the
     * movements already written and recorded as an Adjustment of its own, so
     * this row says what was seen and the ledger says what it did about it.
     *
     * Nothing here is ever updated or deleted — `StockCount` refuses both at
     * the model — because a wrong count is answered by counting again, which
     * is what somebody would physically do anyway.
     */
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();

            /** Cascades with the Product: a Product force-deleted is one with nothing left to count. A soft-deleted one keeps everything. */
            $table->foreignId('product_id')->index()->constrained()->cascadeOnDelete();

            /** What was on the shelf. Unsigned: a User counts what is there, and nobody holds less than none of something. */
            $table->unsignedInteger('quantity');

            /**
             * Why the ledger was out, from `AdjustmentCause` — a string
             * column cast to the PHP enum rather than a native MySQL enum, so
             * a cause added later is a deployment of code and not an
             * `ALTER TABLE` that locks the table to add a word to a list.
             */
            $table->string('cause');

            /** Whatever the counter had to add, where they had anything. */
            $table->text('note')->nullable();

            /**
             * Who counted, so a surprising correction has somebody to ask
             * about it. Null where the User has since been deleted: the count
             * outlives the account, and an Adjustment losing its name is
             * better than a person's departure taking the ledger with it.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
