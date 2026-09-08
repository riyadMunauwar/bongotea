<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            /**
             * One row per group, and the row is the whole group: the key
             * names the group class and the JSON holds every property it
             * stores. There is no per-key row and no group column, so a
             * group is read with one query and written with one upsert.
             * A property a group marks as secret is encrypted on its own
             * inside the JSON; the column itself is plain (ADR-0012).
             *
             * The key is the primary key: a surrogate id would be a second
             * way to name a row that nothing reads by.
             */
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
