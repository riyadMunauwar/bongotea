<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            /**
             * A Customer is a phone number and nothing else. No name and no
             * address column: those belong to each Order, because "latest known
             * name" is a query, not a column — a typo on the third order would
             * otherwise silently become the customer's identity.
             *
             * Stored in one canonical form (see App\Support\PhoneNumber).
             * Load-bearing rather than cosmetic: holding `01712-345678` and
             * `+8801712345678` as different strings creates two Customers and
             * breaks the only feature this table exists to provide.
             */
            $table->string('phone')->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
