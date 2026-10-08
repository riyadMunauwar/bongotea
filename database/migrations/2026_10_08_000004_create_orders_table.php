<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Customer's committed request, with everything it needs to be
     * fulfilled snapshotted onto it. Money is in minor units.
     *
     * Status and payment method are strings cast to enums rather than native
     * ENUM columns, so adding a case is a code change and never a migration.
     * No index serves search: Filament searches with a leading wildcard,
     * which no B-tree index can answer.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_district');
            $table->string('shop_name')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->string('delivery_label')->nullable();
            $table->unsignedBigInteger('delivery_charge')->default(0);
            $table->string('payment_method')->default('cash_on_delivery');
            $table->unsignedBigInteger('total')->default(0);
            $table->string('status')->default('new');
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
