<?php

use App\Enums\OrderKind;
use App\Models\Cart;
use App\Models\Order;

/**
 * Every Order names the Cart it came from. Those captured before Carts
 * existed are counted under the starting Cart of their kind, with its name,
 * so history is not a hole.
 */
it('backfills existing Orders to the starting Cart of their kind, with its name', function () {
    $migration = require database_path('migrations/2026_10_15_102457_add_cart_to_orders_table.php');

    $migration->down();

    $bulk = Order::factory()->create(['kind' => OrderKind::Bulk]);
    $retail = Order::factory()->create(['kind' => OrderKind::Retail]);

    $migration->up();

    $bulkCart = Cart::startingFor(OrderKind::Bulk);
    $retailCart = Cart::startingFor(OrderKind::Retail);

    expect($bulk->refresh()->cart_id)->toBe($bulkCart->getKey())
        ->and($bulk->cart_name)->toBe('পাইকারি অর্ডার করুন')
        ->and($bulkCart->kind)->toBe(OrderKind::Bulk)
        ->and($retail->refresh()->cart_id)->toBe($retailCart->getKey())
        ->and($retail->cart_name)->toBe('খুচরা অর্ডার করুন')
        ->and($retailCart->kind)->toBe(OrderKind::Retail);
});

it('backfills to a starting Cart already made, under the name it bears now, and makes no second', function () {
    $migration = require database_path('migrations/2026_10_15_102457_add_cart_to_orders_table.php');

    $migration->down();

    $cart = Cart::factory()->bulk()->create(['name' => 'Wholesale']);
    $cart->forceFill(['starting_kind' => OrderKind::Bulk])->save();
    $order = Order::factory()->create(['kind' => OrderKind::Bulk]);

    $migration->up();

    expect($order->refresh()->cart_id)->toBe($cart->getKey())
        ->and($order->cart_name)->toBe('Wholesale')
        ->and(Cart::query()->count())->toBe(1);
});
