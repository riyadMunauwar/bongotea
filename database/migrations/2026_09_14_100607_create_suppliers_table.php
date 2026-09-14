<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somebody the Merchant buys from, recorded once so that everything ever
     * bought from them reads as one buying history rather than as however
     * somebody spelled the name that day.
     *
     * Three fields and no more: a name, a number to ring, and whatever the
     * buyer needs to remember about them. A Supplier is never a Customer —
     * one is where goods come from and the other is where they go — so
     * nothing here is shared with `customers`, including the phone, which
     * carries no canonical form because nobody is identified by it.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            /** How to reach them, where anybody wrote it down. */
            $table->string('phone')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            /**
             * Soft, like a Product's: a Supplier no longer bought from still
             * has to name every Supply they ever delivered, and a buying
             * history with a hole in it is worse than a row nobody uses.
             */
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
