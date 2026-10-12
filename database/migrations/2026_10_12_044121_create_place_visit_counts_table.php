<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Visits of one day at one place of the store: a breakdown of
     * `visit_counts`, never its source.
     *
     * A place with no record behind it (Search, the Checkout) is stored under
     * `place_id` 0 rather than null, because PostgreSQL counts two nulls as
     * different and the unique index would then let a day hold the same place
     * twice.
     */
    public function up(): void
    {
        Schema::create('place_visit_counts', function (Blueprint $table) {
            $table->id();
            $table->date('visited_on');
            $table->string('place_kind');
            $table->unsignedBigInteger('place_id')->default(0);
            $table->unsignedInteger('visits')->default(0);

            $table->unique(['visited_on', 'place_kind', 'place_id']);
            $table->index(['place_kind', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_visit_counts');
    }
};
