<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filing a User away is a soft delete.
 *
 * A User who has left is filed away rather than erased, because deleting the
 * row would null the `user_id` on every Stock Count and Adjustment they made
 * — and that name is the only thing that lets a surprising figure be asked
 * about (the `user()` relations read `withTrashed()` for exactly this). It
 * also locks them out by construction: the auth guard reads Users through the
 * soft-delete scope, so a filed-away row cannot be signed in at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
