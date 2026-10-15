<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The bulk order and packet basket Pages become ordinary Pages (ADR-0038):
     * each holds the Cart Block of its starting Cart, and every link to a Cart
     * names it, so nothing needs to find them by a role any more.
     */
    public function up(): void
    {
        DB::table('pages')->whereIn('system_role', ['bulk_order', 'packet_basket'])->update(['system_role' => null]);
    }

    /**
     * Left as it is: an ordinary Page cannot be told which job it once did.
     */
    public function down(): void {}
};
