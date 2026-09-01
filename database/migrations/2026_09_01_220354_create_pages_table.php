<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();

            /** Internal admin label. Never rendered: the body owns its own <title>. */
            $table->string('title');

            /**
             * Unique across every row *including* soft-deleted ones. A slug that
             * was ever live is baked into ad creatives, so freeing it on delete
             * would let a later page silently inherit a running campaign.
             *
             * Deliberately not a composite unique(slug, deleted_at): MySQL treats
             * NULLs as distinct, so two *live* pages could then share a slug —
             * the exact case the constraint exists to prevent.
             */
            $table->string('slug')->unique();

            /** A complete HTML document. The app contributes no markup of its own. */
            $table->longText('body');

            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
