<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two Carts the store opens with, by the kind each starts.
     *
     * A copy of the names as they shipped, not a reference to app code, so
     * this migration reads the same whatever later becomes of them.
     *
     * @var array<string, array{name: string, sort_order: int}>
     */
    private const STARTING_CARTS = [
        'retail' => ['name' => 'খুচরা অর্ডার করুন', 'sort_order' => 0],
        'bulk' => ['name' => 'পাইকারি অর্ডার করুন', 'sort_order' => 1],
    ];

    /**
     * Every Order names the Cart it came from and keeps the name that Cart
     * bore at Capture. Orders captured before Carts existed are counted under
     * the starting Cart of their kind, made here when an installation already
     * has such Orders and does not have it yet.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cart_id')->nullable()->after('kind')->constrained();
            $table->string('cart_name')->nullable()->after('cart_id');
        });

        foreach (self::STARTING_CARTS as $kind => $cart) {
            if (! DB::table('orders')->where('kind', $kind)->whereNull('cart_id')->exists()) {
                continue;
            }

            $cartId = DB::table('carts')->where('starting_kind', $kind)->value('id')
                ?? DB::table('carts')->insertGetId([
                    ...$cart,
                    'kind' => $kind,
                    'starting_kind' => $kind,
                    'is_switched_on' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('orders')->where('kind', $kind)->whereNull('cart_id')->update([
                'cart_id' => $cartId,
                'cart_name' => DB::table('carts')->where('id', $cartId)->value('name'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_id');
            $table->dropColumn('cart_name');
        });
    }
};
