<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Category a line was sold under, fixed at Capture like its price, so
     * moving a Product to another Category never rewrites what was sold as
     * what. Lines captured before this are given their Product's Category as
     * it stands now, once — the best account there is of them.
     *
     * Null where the Category has since been deleted: a Category can go once
     * nothing names it, and history is not a reason to keep one forever.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });

        DB::table('order_lines')
            ->whereNotNull('product_id')
            ->update(['category_id' => DB::raw('(select products.category_id from products where products.id = order_lines.product_id)')]);
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
