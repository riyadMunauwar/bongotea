<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery is now an arrangement a Merchant named and priced on the Page,
     * so an Order snapshots the name beside the amount — the same snapshot an
     * Order Line makes of a Product's name and price, for the same reason.
     * Renaming "Inside Dhaka" next month must not rewrite what a Customer
     * agreed to today, and a User explaining the figure at the door needs the
     * words the Customer saw.
     *
     * Nullable, and left null on every Order taken before this: a Page charging
     * for no delivery at all agrees to no arrangement, and neither did the flat
     * charge these rows were captured under. Filling them in with a name nobody
     * was shown would be inventing the history this column exists to keep.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_label')->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_label');
        });
    }
};
