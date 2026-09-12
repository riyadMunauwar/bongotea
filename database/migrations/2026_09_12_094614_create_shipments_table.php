<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Shipment per attempt to get one Order to a door through one
     * Courier, hung off the Order as its own axis (ADR-0018).
     *
     * The Order's status is untouched by anything here, in either direction:
     * whether a parcel moved is read off these rows, never off `status`, and
     * a Returned parcel never writes the Order's own Cancelled.
     *
     * A parcel that came back and was sent again is two rows, not one
     * overwritten: the first attempt cost money whatever the second one does,
     * and a Merchant paying return fees can only see what returns cost if the
     * failed attempt is still there.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();

            /**
             * Cascades with the Order, like a Payment: an Order that should
             * never have existed takes its attempts with it, while a
             * soft-deleted one keeps them.
             *
             * No index of its own: SQLite creates none for a foreign key, but
             * the `(order_id, status)` index at the foot of this table answers
             * a lookup by Order on its leftmost column, and a second index on
             * the same column would be write cost with no reader.
             */
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /** The Courier by name, and the canonical state by name; string columns for the reason `orders.status` is one. */
            $table->string('courier');
            $table->string('status');

            /**
             * The Courier's own word for that state, kept beside the
             * translation and shown verbatim in the timeline. Null until a
             * Courier has said anything — a Shipment a bulk booking wrote but
             * has not dispatched yet.
             */
            $table->string('raw_status')->nullable();

            /**
             * Our reference for this attempt as the Courier knows it: the
             * order number plus an attempt sequence, never the order number
             * alone. Steadfast requires invoice uniqueness, so a re-send after
             * a return would otherwise be refused at exactly the moment a User
             * is rescuing an Order; and all three couriers echo it back, so it
             * must resolve to one Shipment rather than to an Order with
             * several (ADR-0018).
             */
            $table->string('invoice_reference')->unique();

            /**
             * The Courier's own identifier for the parcel and the code a
             * Customer follows it by, both as the Courier spelled them.
             * Indexed because a webhook arrives carrying the Consignment and
             * nothing of ours.
             */
            $table->string('consignment_reference')->nullable()->index();
            $table->string('tracking_code')->nullable();

            /**
             * What this parcel was sent out to collect at the door, in minor
             * units: nothing where the Order already has a Paid Payment, its
             * total otherwise. Recorded rather than read off the Order,
             * because an Order paid after the parcel left still went out with
             * the full total written on it, and COD in Flight is the sum of
             * what was actually sent out.
             */
            $table->unsignedBigInteger('collect_amount');

            /**
             * What the Courier charged to carry it, in minor units, once the
             * Courier says — which is not always at booking: Steadfast quotes
             * nothing when it takes a parcel and names the charge later,
             * against the payment it settles. Null is "not said yet" and is
             * never read as free.
             */
            $table->unsignedBigInteger('courier_charge')->nullable();

            /**
             * The recipient as this attempt was told it. A snapshot and not a
             * reference: correcting an address writes to the Order as well, so
             * the Order stays current truth while each attempt keeps what it
             * was sent with, and attempt 1 and attempt 2 can and should differ.
             */
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->text('recipient_address');
            $table->string('recipient_district')->nullable();
            $table->text('recipient_note')->nullable();

            /** When the Courier accepted the parcel: the instant the attempt began, which is what days-to-deliver is measured from. */
            $table->timestamp('booked_at')->nullable();

            /**
             * Abandoned: a time and a name, never a status, so the Courier's
             * last word survives being written off (ADR-0018). Written by the
             * action that abandons; nothing here does yet.
             */
            $table->timestamp('abandoned_at')->nullable();
            $table->foreignId('abandoned_by')->nullable()->constrained('users')->nullOnDelete();

            /** What the Courier answered when the parcel was booked, kept whole for a dispute months later. */
            $table->json('booking_response')->nullable();

            $table->timestamps();

            /**
             * The one question every Orders screen asks — what this Order has
             * Active — and, on its leftmost column alone, every lookup of an
             * Order's attempts.
             */
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
