<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The One-Time Code last sent to each number, kept only as a hash and
     * only until it is used, expires or is guessed at too often. One row per
     * number: a new code replaces the one before it.
     */
    public function up(): void
    {
        Schema::create('one_time_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_codes');
    }
};
