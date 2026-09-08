<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Payment per attempt to collect an Order's total through an online
     * Payment Method, hung off the Order as its own axis (ADR-0013).
     *
     * The Order's status is untouched by anything here: whether money moved is
     * read off these rows, never off a column on the Order.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            /**
             * Cascades with the Order: an Order that should never have existed
             * takes its attempts with it, while a soft-deleted one keeps them.
             * Indexed by hand, because "is this Order Paid" is asked of every
             * Thank-you and Retry Page and SQLite creates no index for a
             * foreign key; MySQL reuses this one for the constraint.
             */
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();

            /** The method by name, and the state by name; string columns for the reason `orders.status` is one. */
            $table->string('method');
            $table->string('state');

            /**
             * What this attempt was for, in minor units, and in what money.
             * Recorded rather than read off the Order because an advance or a
             * delivery-charge-only Payment later is a smaller figure and no
             * change of shape.
             */
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            /**
             * The gateway's own id for the Payment, once the Customer's return
             * or a Notification names one; what a Verification asks by.
             * Indexed because a Notification arrives carrying it and nothing
             * else of ours.
             */
            $table->string('gateway_reference')->nullable()->index();

            /**
             * Our reference as the gateway knows it: the order number plus an
             * attempt sequence, because gateways refuse a duplicate invoice
             * id. The Processing Page is addressed by it, so it is unique.
             */
            $table->string('invoice_reference')->unique();

            $table->timestamp('verified_at')->nullable();

            /** What the gateway said when the Payment was started, and when it was asked about, kept whole for a dispute. */
            $table->json('start_response')->nullable();
            $table->json('verification_response')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
