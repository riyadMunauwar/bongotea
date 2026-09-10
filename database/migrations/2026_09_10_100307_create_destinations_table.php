<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One configured place a Provider's events are sent: for Facebook, a
     * dataset and the access token that writes to it.
     *
     * A table rather than a Settings group, because a group encrypts per flat
     * property and holds one row of flat JSON, which cannot express an
     * unbounded user-created list where each item carries its own secret
     * (ADR-0012). Only the credential is sealed; the rest of the row stays
     * readable in SQL, which is the same decision Settings made.
     */
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();

            /** The Merchant's own name for it, so a Page picking one can be told apart from the others. */
            $table->string('label');

            /** The ad platform by name; a string column for the reason `orders.status` is one. */
            $table->string('provider');

            /**
             * The public identifier the Provider addresses this Destination
             * by — Facebook's Dataset ID, TikTok's Pixel Code. Not a secret:
             * it is baked into every cached Page's Pixel snippet, so sealing
             * it would buy nothing and cost a decrypt per render.
             */
            $table->string('dataset')->default('');

            /**
             * The credential that writes to it, sealed with the application
             * encrypter by the model's cast. Never shown back to the
             * Merchant, and never in a database dump in the clear.
             */
            $table->text('access_token')->nullable();

            /** Off pauses reporting without deleting the Destination, for a campaign break. */
            $table->boolean('enabled')->default(true);

            /**
             * Whether a Page that has not picked a Destination reports here.
             * Exactly one per Provider carries it, which the observer keeps
             * true; indexed with the Provider because resolving a Page's
             * Destinations asks exactly that question.
             */
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['provider', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
