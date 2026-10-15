<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carts the Merchant makes, each bulk or retail for good, and the Products
     * assigned to each.
     *
     * `starting_kind` marks the two Carts the store opens with, one of each
     * kind, so install finds them again however they were renamed and never
     * makes a second. A deleted Cart keeps its row, so the Orders and Contents
     * naming it still read its name.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind')->index();
            $table->boolean('is_switched_on')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('starting_kind')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cart_product', function (Blueprint $table) {
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['cart_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_product');
        Schema::dropIfExists('carts');
    }
};
