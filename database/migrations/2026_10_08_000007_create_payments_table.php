<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every attempt to collect an Order's total online, and what the gateway
     * said about it (ADR-0013).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('state');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('gateway_reference')->nullable()->index();
            $table->string('invoice_reference')->unique();
            $table->timestamp('verified_at')->nullable();
            $table->json('start_response')->nullable();
            $table->json('verification_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
