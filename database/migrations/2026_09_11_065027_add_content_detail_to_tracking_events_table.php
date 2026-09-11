<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            /**
             * Whether this event may name what was bought, or carries the
             * money alone.
             *
             * Frozen onto the row rather than read back off the Page at
             * delivery, because a Provider driver reads the row it is handed
             * and the Order that row points at, and nothing else — and
             * because the Page that decided it may be in the bin by the time
             * the sweep runs.
             *
             * True for every row already written: that is what they all sent.
             */
            $table->boolean('content_detail')->default(true)->after('click_ids');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->dropColumn('content_detail');
        });
    }
};
