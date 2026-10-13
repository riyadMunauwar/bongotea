<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Product's Packs (ADR-0034): sealed retail sizes known by their weight
     * alone, so no two of one Product weigh the same. Money in minor units, as
     * on the Product. A Product with none is bulk-only.
     */
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('grams');
            $table->unsignedBigInteger('mrp');
            $table->unsignedBigInteger('price');
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'grams']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packs');
    }
};
