<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Merchant may now switch a built-in field off, so three of the four
     * details an Order has always carried become answers a Checkout might never
     * have asked for. A question that was not asked stores a null rather than a
     * blank: an empty string reads as "they left it out", and only a null says
     * "we never asked".
     *
     * Widening a column touches no row, so every Order taken before this keeps
     * exactly the details it was captured with.
     *
     * `phone` is deliberately not in the list. A Customer is their phone
     * number and a cash-on-delivery Order nobody can call is not fulfillable,
     * so it is the one field with no off-switch — see ADR-0006.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->change();
            $table->text('customer_address')->nullable()->change();
            $table->string('customer_district')->nullable()->change();

            /**
             * Off in a new Config, so most Orders will never carry one. It sits
             * beside the phone because it is the other way of reaching a
             * Customer, not a delivery detail.
             */
            $table->string('customer_email')->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_email');
        });

        /**
         * Narrowing again would fail on an Order captured while the columns
         * stood open, so the absences are filled before the columns close. A
         * blank is the shape those columns held before this ran: rolling back
         * loses the distinction between "never asked" and "left empty", which
         * is the whole of what it is rolling back.
         */
        DB::table('orders')->whereNull('customer_name')->update(['customer_name' => '']);
        DB::table('orders')->whereNull('customer_address')->update(['customer_address' => '']);
        DB::table('orders')->whereNull('customer_district')->update(['customer_district' => '']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name')->nullable(false)->change();
            $table->text('customer_address')->nullable(false)->change();
            $table->string('customer_district')->nullable(false)->change();
        });
    }
};
