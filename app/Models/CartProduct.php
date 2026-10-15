<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * One Product assigned to one Cart, refused unless the Product is sold the
 * Cart's way: a bulk Cart never offers a tea it cannot sell পাইকারি, nor a
 * retail Cart one it cannot sell খুচরা.
 *
 * Checked here because every assignment, the form's included, is attached
 * through this class (`Cart::products()` uses it).
 */
class CartProduct extends Pivot
{
    protected $table = 'cart_product';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (CartProduct $assignment): void {
            $cart = Cart::withTrashed()->findOrFail($assignment->cart_id);
            $product = Product::withTrashed()->findOrFail($assignment->product_id);

            if (! $product->isSoldAs($cart->kind)) {
                throw new LogicException("{$product->name} is not sold the way the Cart {$cart->name} sells.");
            }
        });
    }
}
