<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outbox of what is to be reported to each Provider, what a browser
     * told us for an Order whose Purchase is still to come, and the
     * store-wide Visit count per day.
     *
     * The outbox is idempotent by its unique index, not by a check at the
     * call site, and holds no personal data: a Provider reads the Customer
     * off the Order at delivery.
     */
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('moment');
            $table->string('event');
            $table->string('deduplication_id');
            $table->timestamp('occurred_at');
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('click_ids')->nullable();
            $table->boolean('content_detail')->default(true);
            $table->json('selection')->nullable();
            $table->string('state');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'deduplication_id', 'event']);
            $table->index(['state', 'provider', 'id']);
        });

        Schema::create('tracking_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('click_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('visit_counts', function (Blueprint $table) {
            $table->id();
            $table->date('visited_on')->unique();
            $table->unsignedInteger('visits')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_counts');
        Schema::dropIfExists('tracking_contexts');
        Schema::dropIfExists('tracking_events');
    }
};
