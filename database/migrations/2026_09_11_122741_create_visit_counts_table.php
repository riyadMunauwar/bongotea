<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Visit aggregate: one row per Page per day, holding how many times
     * that Page was loaded.
     *
     * A count and never a log. A Visit is every load of a published Page, so
     * a row per load would be the largest table in the installation and would
     * hold, per row, an address and a browser that serve no purpose once the
     * Moment has been reported. What a Merchant is owed is the figure, so the
     * figure is what is stored — which is also why this table is kept forever
     * while everything else tracking writes is pruned: it identifies nobody,
     * and last month's Conversion Rate is still worth having next month.
     *
     * The day is the Merchant's own, not UTC: a Reporting Window cuts its
     * days at midnight in the Merchant's timezone, and a figure bucketed at
     * the server's midnight would disagree with the Orders it is set against.
     * It is stored as a plain `Y-m-d` and read as one, so no driver's idea of
     * a date can move a row between days.
     */
    public function up(): void
    {
        Schema::create('visit_counts', function (Blueprint $table) {
            $table->id();

            /**
             * Kept when the Page is deleted for good, rather than cascading
             * with it. The aggregate is the one thing tracking writes that is
             * kept indefinitely, and a Merchant who tidies up a Page must not
             * find last month's Visits smaller than they were — a figure that
             * shrinks behind you is wrong in a way nobody notices until they
             * are comparing two months. What is lost is only the attribution:
             * an orphaned row still counts towards the installation, and no
             * leaderboard row claims it, because a hard-deleted Page's Orders
             * sit under a recorded slug and a slug was never counted against.
             *
             * A soft-deleted Page keeps its rows and its attribution, and
             * still reports.
             */
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();

            /** The day, in the Merchant's timezone, as `Y-m-d`. */
            $table->date('visited_on');

            $table->unsignedInteger('visits')->default(0);

            /**
             * One row per Page per day, which is what makes counting a Visit
             * an increment of an existing row rather than an insert — and
             * what makes two visitors arriving at once still one row. Rows
             * orphaned by a hard-deleted Page fall outside it, since every
             * driver reads two nulls as distinct; nothing ever writes to one
             * again, so there is nothing for it to protect there.
             */
            $table->unique(['page_id', 'visited_on']);

            /** The reporting read: every Page's counts across a window of days. */
            $table->index('visited_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_counts');
    }
};
