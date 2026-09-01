<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /**
             * Ordering for the admin only. Nothing public resolves a Product by
             * position — that would silently retarget a live page on reorder.
             */
            $table->unsignedInteger('position')->default(0);

            $table->unique(['page_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_product');
    }
};
