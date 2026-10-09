<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Questions sent through the contact form, kept until a User has called
     * back and closed them. The phone is stored in its canonical form; who
     * handled one is the User who last moved it on from New.
     */
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('topic')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new');
            $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            /** The sidebar badge counts New on every request; equality-tested, so an index serves it. */
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
