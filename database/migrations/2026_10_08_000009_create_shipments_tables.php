<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Shipment per attempt to get one Order to a door through one
     * Courier (ADR-0018), and every status that attempt was reported in.
     * A parcel sent again is a second row, never an overwritten first.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('courier');
            $table->string('status');
            $table->string('raw_status')->nullable();
            $table->string('invoice_reference')->unique();
            $table->string('consignment_reference')->nullable()->index();
            $table->string('tracking_code')->nullable();
            $table->unsignedBigInteger('collect_amount');
            $table->unsignedBigInteger('courier_charge')->nullable();
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->text('recipient_address');
            $table->string('recipient_district')->nullable();
            $table->text('recipient_note')->nullable();
            $table->json('parcel_details')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->timestamp('stuck_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->foreignId('abandoned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('booking_response')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['courier', 'polled_at']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('raw_status')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('recorded_at');
            $table->string('deduplication_id');
            $table->timestamps();

            $table->unique(['shipment_id', 'deduplication_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
