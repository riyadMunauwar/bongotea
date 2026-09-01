<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /**
             * A reference, never a source of truth. Nullable and nulled on a
             * force-delete: the line already holds everything it needs to be
             * fulfilled, so losing the Product loses nothing that matters.
             */
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            /**
             * The snapshot. Reading the Product's price live would be a
             * correctness bug, not a trade-off: in COD the fulfiller collects
             * 1,290 for an order placed at 990, and that is a refused delivery
             * plus a courier fee on a returned parcel.
             *
             * `line_total` is stored despite being derivable today — it is the
             * figure a human quoted on the confirmation call, and it must
             * survive any later change to rounding, discounts or per-page
             * pricing.
             */
            $table->string('product_name');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
