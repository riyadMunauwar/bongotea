<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /**
             * The Page-scoped reports filter on `page_id` and then cut a
             * Reporting Window out of `created_at`, which the two existing
             * indexes cannot serve: `created_at` alone reads every Order in
             * the window and drops the other Pages', and `status, created_at`
             * leads on the wrong column. Composite so a Page's window is one
             * range read, in capture order, rather than a filter over it.
             *
             * MySQL already gives the foreign key an implicit index on
             * `page_id` alone; SQLite gives it nothing. Leading on `page_id`
             * here serves the key on both, and the equality-tested column is
             * what the resources rule says an index is for — this is a
             * GROUP BY's filter, not a searchable() column.
             */
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['page_id', 'created_at']);
        });
    }
};
