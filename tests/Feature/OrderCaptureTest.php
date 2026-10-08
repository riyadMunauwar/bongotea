<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Inventory\StockLedger;
use App\Support\Orders\CheckoutUnavailable;

/**
 * Capture: a Cart becoming an Order.
 *
 * Capture takes cart lines (a Product and how many kilograms of it) and the
 * delivery details, and nothing priced: every figure on the Order is read off
 * the Products themselves. Asserted on the Order and the lines that land.
 */
it('captures one line per product in the cart, priced from the product and snapshotted', function () {
    $premium = Product::factory()->create(['name' => 'Premium', 'price' => 60000]);
    $strong = Product::factory()->create(['name' => 'Strong', 'price' => 50000]);

    $order = orderAsCaptured(lines: [
        ['product' => $premium, 'kilograms' => 8],
        ['product' => $strong, 'kilograms' => 4],
    ]);

    expect($order->lines->map->only(['product_id', 'product_name', 'unit_price', 'quantity', 'line_total'])->all())->toBe([
        ['product_id' => $premium->getKey(), 'product_name' => 'Premium', 'unit_price' => 60000, 'quantity' => 8, 'line_total' => 480000],
        ['product_id' => $strong->getKey(), 'product_name' => 'Strong', 'unit_price' => 50000, 'quantity' => 4, 'line_total' => 200000],
    ])
        ->and($order->subtotal)->toBe(680000)
        ->and($order->total)->toBe(680000)
        ->and($order->status)->toBe(OrderStatus::New);

    /** A later repricing of the Product never rewrites what was agreed. */
    $premium->update(['price' => 1]);

    expect($order->lines()->first()->unit_price)->toBe(60000);
});

it('records the delivery details, the shop name and the note, against the customer the phone names', function () {
    $order = orderAsCaptured([
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'address' => 'House 4, Road 12, Banani',
        'district' => 'Dhaka',
        'shop_name' => 'Rahim Tea Stall',
        'note' => 'Call before coming',
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);

    expect($order->customer_name)->toBe('Rahim Uddin')
        ->and($order->customer_phone)->toBe('+8801712345678')
        ->and($order->customer_address)->toBe('House 4, Road 12, Banani')
        ->and($order->customer_district)->toBe('Dhaka')
        ->and($order->shop_name)->toBe('Rahim Tea Stall')
        ->and($order->note)->toBe('Call before coming')
        ->and($order->deliveryNote())->toBe('Call before coming')
        ->and($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->customer->is(Customer::sole()))->toBeTrue();
});

it('stores a blank shop name and note as nothing given', function () {
    $order = orderAsCaptured(['shop_name' => '', 'note' => '  ']);

    expect($order->shop_name)->toBeNull()
        ->and($order->note)->toBeNull()
        ->and($order->deliveryNote())->toBeNull();
});

it('gives every order a number of its own', function () {
    $first = orderAsCaptured();
    $second = orderAsCaptured();

    expect($first->order_number)->not->toBe($second->order_number)
        ->and($first->order_number)->toMatch('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]+$/');
});

it('stamps each line with what its units cost at capture', function () {
    $tea = Product::factory()->create(['price' => 60000]);

    app(StockLedger::class)->recordSupply(
        supplier: Supplier::factory()->create(),
        suppliedOn: now()->toDateString(),
        lines: [['product_id' => $tea->getKey(), 'quantity' => 10, 'unit_cost' => 40000]],
    );

    $order = orderAsCaptured(lines: [['product' => $tea, 'kilograms' => 2]]);

    expect($order->lines()->sole()->unit_cost)->toBe(40000);
});

it('stamps no cost on a line whose product nothing has been recorded buying', function () {
    expect(orderAsCaptured()->lines()->sole()->unit_cost)->toBeNull();
});

/**
 * A Capture sells exactly what it is asked to or refuses, and is never
 * corrected silently: a parcel holding less than the Customer agreed to is
 * refused at the door, at the Merchant's expense.
 */
it('refuses a cart it cannot sell as it stands, and writes nothing', function (Closure $cart, string $because) {
    expect(fn () => orderAsCaptured(lines: $cart()))
        ->toThrow(CheckoutUnavailable::class, $because);

    expect(Order::count())->toBe(0);
})->with([
    'an empty cart' => [fn (): array => [], 'empty Cart'],
    'a product taken out of the catalogue' => [function (): array {
        $retired = Product::factory()->create();
        $retired->delete();

        return [['product' => $retired, 'kilograms' => 1]];
    }, 'no longer sold'],
    'no kilograms' => [fn (): array => [['product' => Product::factory()->create(), 'kilograms' => 0]], 'no kilograms'],
    'a product twice' => [function (): array {
        $tea = Product::factory()->create();

        return [['product' => $tea, 'kilograms' => 1], ['product' => $tea, 'kilograms' => 2]];
    }, 'appears twice'],
]);

it('refuses an order with no district to deliver to', function (?string $district) {
    expect(fn () => orderAsCaptured(['district' => $district]))
        ->toThrow(CheckoutUnavailable::class, 'no District');

    expect(Order::count())->toBe(0);
})->with([
    'blank' => '',
    'missing' => null,
]);
