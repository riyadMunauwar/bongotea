<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per thing that happened to a Shipment, as the system came to
     * know it.
     *
     * Two times and not one (ADR-0018): when the Courier says it happened,
     * where the Courier says anything at all, and when we found out, which is
     * always. The two sit far apart for a Courier that must be asked rather
     * than one that announces, and a timeline drawing only ours would
     * quietly misdate every parcel from the couriers that say least.
     *
     * Kept indefinitely, like the Shipments and the Orders they belong to.
     * This is fulfilment data holding no browser identifier, which is
     * precisely why Tracking Context is pruned and this is not.
     */
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();

            /** Cascades with the Shipment: an attempt that is gone has no history to keep. */
            $table->foreignId('shipment_id')->index()->constrained()->cascadeOnDelete();

            /** Where the Shipment stood, in our words and in the Courier's own. */
            $table->string('status');
            $table->string('raw_status')->nullable();

            /** The Courier's time, where the Courier gives one. Most do not, on most of what they say. */
            $table->timestamp('occurred_at')->nullable();

            /**
             * Ours, and never null: the instant this became known here. The
             * fact the timeline draws for a Courier that gives no time of its
             * own — `created_at` beside it is the row's bookkeeping and is
             * never read as a delivery time.
             */
            $table->timestamp('recorded_at');

            /**
             * What two tellings of the same happening agree on: the Courier's
             * own word and its own time, spelled by
             * `ShipmentEvent::deduplicationIdFor()`.
             *
             * A string rather than the two columns themselves, because
             * `occurred_at` is null for most of what a Courier says and SQL
             * counts two nulls as distinct — a unique key over a nullable
             * column would let the same happening in again on every poll,
             * which is the one thing this constraint exists to stop.
             */
            $table->string('deduplication_id');

            $table->timestamps();

            /**
             * The invariant at the database rather than at the call site
             * (ADR-0019): RedEx answers every poll with its whole tracking
             * history and the sweep re-imports it each time, and a webhook
             * and a poll about the same happening arrive minutes apart. One
             * row either way.
             */
            $table->unique(['shipment_id', 'deduplication_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
