<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the browser told us when an Order was captured, kept until the
     * Moment that will need it has happened.
     *
     * An Order paid online reports its `Purchase` when a Verification says
     * Paid, and that happens with no browser present: from the sweep, or
     * from a gateway's own Notification. The click identifiers, the IP
     * address and the User-Agent of the visit that placed the Order are
     * therefore recorded here at Capture and read back then. They cannot
     * come from the outbox, because such an Order produces no event at
     * Capture and so leaves no row to copy from.
     *
     * **Its own table, not columns on `orders`.** Orders are kept
     * indefinitely as fulfilment and accounting records and an IP address
     * should not be, and an Order records what it needs to be fulfilled — a
     * browser identifier is not that.
     */
    public function up(): void
    {
        Schema::create('tracking_contexts', function (Blueprint $table) {
            $table->id();

            /**
             * One row per Order, and gone with it: a Tracking Context that
             * names no Order can never be reported for anything.
             */
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            /** The Page the visitor was on, which is the address a Provider is told the Moment happened at. */
            $table->string('source_url')->nullable();

            /**
             * Carried as-is rather than hashed, like the outbox's own copies,
             * because a Provider matches on these itself and a hashed one
             * matches nobody.
             */
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            /**
             * The click identifiers, keyed by the name each Provider knows
             * them under, so one bag serves every Provider and each takes
             * what it recognises. Empty for a visitor whose browser ran no
             * script, which still captures an Order.
             */
            $table->json('click_ids')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_contexts');
    }
};
