<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named Menus and their items, nested by `parent_id` and ordered by
     * `position` among siblings. A Menu is assigned to a place (the header)
     * by that place's settings group, never by a column here (ADR-0033).
     *
     * An item points at a Page by the `linkable` morph, at a section by
     * `anchor` (on that Page, or Home), at an address by `url`, or at
     * nothing when it is a Heading. Items go with their Menu and with their
     * parent; a Save replaces the whole tree, so nothing else deletes one.
     */
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('label');
            $table->string('type');
            $table->string('note')->nullable();
            $table->nullableMorphs('linkable');
            $table->string('anchor')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }
};
