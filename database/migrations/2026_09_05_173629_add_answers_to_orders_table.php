<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Merchant may now add questions of their own to a Page's Checkout, and
     * the answers land here: an ordered list of what was asked, in the wording
     * it was asked in, beside what was said.
     *
     * A column each was never an option — the questions are the Merchant's and
     * differ per Page — so this is the snapshot ADR-0006 puts the other half of
     * the field list into. It carries the label and the type as well as the
     * value, because an Order records everything it needs to be fulfilled
     * without consulting anything else: relabelling a question, deleting it or
     * force-deleting the Page must leave a past Order reading exactly as it did.
     *
     * Nullable, and left null on every Order taken before this. Those Orders
     * were captured on Checkouts that asked nothing of the kind, and writing an
     * empty list onto each of them would be a row rewritten to say what its
     * absence already says. The cast reads null as no answers, so nothing
     * downstream branches on which it is.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('customer_district');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
    }
};
