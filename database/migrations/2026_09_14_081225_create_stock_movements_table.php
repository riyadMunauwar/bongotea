<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger: one row per thing that happened to a quantity of a Product.
     *
     * On Hand is the sum of these rows and is stored nowhere (ADR-0021).
     * There is no quantity column on `products` and none is coming: a typed
     * figure has no history behind it, and the whole of this table is the
     * history. A Product nobody has counted has no rows here, which is what
     * makes its On Hand unknown rather than zero.
     *
     * Rows are written by `StockLedger` and by nothing else, and they are
     * never updated or deleted — `StockMovement` refuses both at the model.
     * A mistake is answered by another movement, and the pair stays on the
     * record.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            /**
             * Cascades with the Product, which is the force-delete case and
             * not the ordinary one: a soft-deleted Product keeps every
             * movement it ever had, so past reports do not acquire a hole
             * when somebody tidies the catalogue.
             */
            $table->foreignId('product_id')->index()->constrained()->cascadeOnDelete();

            /** Signed: what arrived is positive, what left is negative, and the sum is the whole figure. */
            $table->integer('quantity');

            /**
             * Why it moved, from `StockMovementReason` — a string column cast
             * to the PHP enum rather than a native MySQL enum, so the reasons
             * the later tickets bring are a deployment of code rather than an
             * `ALTER TABLE` on a table every stock figure is read from.
             */
            $table->string('reason');

            /**
             * What a unit was worth at the moment it moved, in minor units
             * like `products.price`, stamped here and never recomputed
             * (ADR-0022).
             *
             * Null is *unknown*, and today it is always null: the weighted
             * average is read from what has been supplied, and nothing can be
             * supplied until Supply exists. An Adjustment written now
             * therefore carries no cost, the history draws nothing for it,
             * and a Product with no known cost must never be made to read as
             * zero — which is the same choice On Hand makes against `0`.
             */
            $table->integer('unit_cost')->nullable();

            /**
             * Who caused it, where a person did. Null where the User has
             * since been deleted, and null in its own right for the movements
             * the later tickets write from a Courier's word rather than from
             * anybody's hand.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /**
             * What caused it: a Stock Count today, a Supply's line and a
             * Shipment when those arrive. Every movement has one — a row here
             * with nothing behind it would be a figure nobody can be asked
             * about, which is the state this whole table exists to make
             * impossible.
             *
             * Written out rather than with `morphs()`, which would add an
             * index over the two columns that the unique key below already
             * serves as its own leading pair — dead weight on every write to
             * buy a lookup that is already indexed.
             */
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            $table->timestamps();

            /**
             * The invariant at the database rather than at the call site, the
             * way `shipment_events` holds its own (ADR-0019): one cause moves
             * one Product one way exactly once, however many times the thing
             * that caused it is re-asserted. A Shipment reaching Booked
             * through a poll and a webhook minutes apart deducts its parcel
             * once, and a ledger that drifted downward under precisely the
             * traffic the courier layer was built for would be wrong in the
             * one way nobody could ever reconstruct.
             *
             * A cause that has to move the same Product the same way twice
             * does not exist and must not be invented: it is answered by a
             * second cause, which is what a second Stock Count already is.
             */
            $table->unique(['source_type', 'source_id', 'product_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
