<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outbox: one row per provider event per Destination, written during
     * a request and delivered by `tracking:sweep`.
     *
     * No web request ever calls a Provider, so this table is what stands
     * between a Customer's page load and somebody else's outage. A row is
     * written and the request returns.
     *
     * It holds **no personal data** — no phone, email or name, hashed or
     * otherwise. What a Provider is told about the Customer is built in the
     * sweep from the Order the row points at, so this table can be pruned
     * without touching a fulfilment record, and a dump of it identifies
     * nobody.
     */
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();

            /**
             * Cascades with the Destination: a Destination that is deleted
             * has nowhere left to deliver, and rows kept for it would be
             * undeliverable forever.
             */
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();

            /** What happened in our own vocabulary, and what the Provider calls it. */
            $table->string('moment');
            $table->string('event');

            /**
             * What the browser copy and this copy agree on, so the Provider
             * counts one event and not two. The order number for Capture and
             * Paid; a browser-generated identifier for Visit and Checkout
             * Started.
             */
            $table->string('deduplication_id');

            /**
             * When the Moment happened, which is not when it is sent: a row
             * delivered by a sweep an hour later still reports the instant
             * the Order was captured, and it is this that the Provider's
             * acceptance window is measured from.
             */
            $table->timestamp('occurred_at');

            /** The Page it happened on, and the attribution that survives the Page being deleted. */
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page_slug')->nullable();

            /** The Order, where the Moment has one. Cascades like a Payment does. */
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();

            /**
             * What the browser told us, sent as-is rather than hashed,
             * because a Provider matches on these itself.
             */
            $table->string('source_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            /**
             * The click identifiers, keyed by the name each Provider knows
             * them under, so one bag serves every Provider and each takes
             * what it recognises.
             */
            $table->json('click_ids')->nullable();

            $table->string('state');

            /** How many times delivery has been attempted, and what went wrong the last time. */
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            /**
             * The invariant, at the database rather than in three call sites:
             * exactly one of a given event per Destination per deduplication
             * id. A Verification reached from the Processing Page, a
             * Notification and the sweep at once writes one Purchase.
             */
            $table->unique(['destination_id', 'deduplication_id', 'event']);

            /** The sweep's one query: pending, by Destination, oldest first. */
            $table->index(['state', 'destination_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
