<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Product's worth of a Supply: how many units arrived and what each
     * one cost.
     *
     * An `Order Line` read from the buying side, and the thing a Stock
     * Movement points at when it says a Supply caused it — one line, one
     * movement, so a delivery of four Products is four rows a User can read
     * separately.
     */
    public function up(): void
    {
        Schema::create('supply_lines', function (Blueprint $table) {
            $table->id();

            /** Cascades: the lines are the document, and a Supply without them is nothing at all. */
            $table->foreignId('supply_id')->index()->constrained()->cascadeOnDelete();

            /** Restricted, for the reason `supplies.supplier_id` is: force-deleting a Product must not silently rewrite what was bought. */
            $table->foreignId('product_id')->index()->constrained()->restrictOnDelete();

            /** How many arrived. Unsigned: a delivery that took stock away is not a delivery. */
            $table->unsignedInteger('quantity');

            /**
             * What one unit cost, in minor units like `products.price`
             * (ADR-0022). Not nullable: a Supply is where cost enters the
             * system, and a line that declines to say what was paid would
             * leave every figure downstream of it guessing.
             */
            $table->integer('unit_cost');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_lines');
    }
};
