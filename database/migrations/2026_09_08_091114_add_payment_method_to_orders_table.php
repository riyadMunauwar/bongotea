<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Payment Method an Order was placed under, by name.
     *
     * Snapshotted on the Order like the delivery arrangement beside it: a Page
     * that stops offering bKash next month must not rewrite how this Customer
     * said they would pay today.
     *
     * Defaulted, and backfilled, to Cash on Delivery — unlike `delivery_label`,
     * which was left null on older rows because no arrangement had been agreed.
     * Here the history is known: every Order taken before this column existed
     * was paid at the door, because there was no other way to pay (ADR-0013).
     *
     * A string column and never a native ENUM, for the reason `status` is one:
     * SQLite is the test database and the two dialects diverge on altering an
     * enum column.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->default('cash_on_delivery')->after('delivery_charge');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
