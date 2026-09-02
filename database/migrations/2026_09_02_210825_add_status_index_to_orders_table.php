<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /**
             * The original table indexed `created_at` alone, on the reasoning that
             * the queue is "today's orders, newest first" and a date range is the
             * only listed filter. A second access pattern has since become the
             * hotter of the two.
             *
             * `OrderResource::getNavigationBadge()` counts orders in the `New`
             * status, and Filament renders panel navigation on *every* admin page —
             * so that count runs on each request, not just on the queue screen.
             * Unindexed, it is a full scan of the one table that grows with
             * traffic. The same index serves the queue's status filter, which
             * sorts by `created_at` within a chosen status.
             *
             * Composite rather than a bare `status`: leading on `status` answers
             * the badge's equality test, and carrying `created_at` lets the
             * filtered queue read rows already in sort order instead of sorting
             * the matched set.
             */
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
