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
             * What was being bought when the Moment happened, for a Moment
             * that has no Order to read it from.
             *
             * Only Checkout Started fills it: a visitor starting the form has
             * ticked Products and named quantities, and there is no Order yet
             * to carry either. Capture and Paid leave it null, because the
             * Order they point at holds the same answer and holds it better.
             *
             * Frozen at Moment time for the reason `content_detail` is: the
             * sweep runs minutes or hours later, and the Page whose Products
             * were priced may be repriced or in the bin by then.
             *
             * No personal data, like every other column here — Product ids,
             * quantities and prices identify nobody.
             */
            $table->json('selection')->nullable()->after('content_detail');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->dropColumn('selection');
        });
    }
};
