<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Inventory\StockLedger;
use App\Support\Orders\OrderOwnership;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\PaymentSettings;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Payments\SslCommerzScript;

/**
 * The Checkout: one step from the browser's Cart to an Order.
 *
 * The browser sends the Cart's lines and the Quote it showed — each line's
 * Tier Price, the Delivery Charge and the total — and Capture prices the
 * lines again through the pricing module. A Quote that disagrees is refused
 * with the figures as they are now, and nothing is written (ADR-0029).
 *
 * The Cart throughout: Premium, 600.00 a kilogram up to five and 550.00 from
 * six against an MRP of 650.00, and Strong at a flat 500.00, its MRP. Four of
 * one and three of the other are seven kilograms, so Premium charges 550.00,
 * the Saving is 400.00, and the Delivery Charge is the 6–10 kg band's 150.00.
 */
function premium(array $attributes = []): Product
{
    return Product::factory()->create([
        'name' => 'Premium',
        'mrp' => 65000,
        'tiers' => [['min' => 1, 'max' => 5, 'price' => 60000], ['min' => 6, 'max' => null, 'price' => 55000]],
        ...$attributes,
    ]);
}

function strong(array $attributes = []): Product
{
    return Product::factory()->create(['name' => 'Strong', 'price' => 50000, ...$attributes]);
}

/**
 * What the checkout page posts: the details typed, the Cart's lines, and the
 * Quote the buyer was looking at.
 *
 * @param  list<array{0: Product, 1: int, 2: int}>  $lines  Product, Kilograms, the Tier Price shown
 * @param  array<string, mixed>  $details
 * @return array<string, mixed>
 */
function checkoutOf(array $lines, int $deliveryCharge, int $total, array $details = []): array
{
    return [
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'district' => 'Dhaka',
        'address' => 'House 4, Road 12, Banani',
        'shop_name' => 'Rahim Tea Stall',
        'note' => 'Call before coming',
        'lines' => array_map(fn (array $line): array => ['productId' => $line[0]->getKey(), 'kilograms' => $line[1]], $lines),
        'quote' => [
            'lines' => array_map(fn (array $line): array => ['productId' => $line[0]->getKey(), 'tierPrice' => $line[2]], $lines),
            'deliveryCharge' => $deliveryCharge,
            'total' => $total,
        ],
        ...$details,
    ];
}

/** The seven-kilogram Cart, quoted exactly as the store prices it. */
function sevenKilograms(Product $premium, Product $strong, array $details = []): array
{
    return checkoutOf([[$premium, 4, 55000], [$strong, 3, 50000]], 15000, 385000, $details);
}

it('draws the checkout with every district and no payment choice while only cash on delivery is on', function () {
    $tea = premium();

    $this->get(route('checkout'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checkout')
            ->has('districts', 64)
            ->where('districts.0', 'Bagerhat')
            ->where('paymentMethods', [['name' => 'cash_on_delivery', 'logoUrl' => asset('images/payment-methods/cash-on-delivery.svg')]])
            ->where('productNames.'.$tea->getKey(), 'Premium'));
});

it('offers a payment choice once more than one payment method is switched on, each with its uploaded logo or else the default', function () {
    Storage::fake(PaymentSettings::LOGO_DISK);
    (new SslCommerzScript)->makeUsable();
    app(PaymentSettings::class)->with(['sslcommerzLogo' => 'payment-methods/ours.svg'])->save();

    $this->get(route('checkout'))
        ->assertInertia(fn (Assert $page) => $page->where('paymentMethods', [
            ['name' => 'cash_on_delivery', 'logoUrl' => asset('images/payment-methods/cash-on-delivery.svg')],
            ['name' => 'sslcommerz', 'logoUrl' => Storage::disk(PaymentSettings::LOGO_DISK)->url('payment-methods/ours.svg')],
        ]));
});

it('captures the cart priced by the store, and hands the placing browser the order', function () {
    $premium = premium();
    $strong = strong();

    app(StockLedger::class)->recordSupply(
        supplier: Supplier::factory()->create(),
        suppliedOn: now()->toDateString(),
        lines: [['product_id' => $premium->getKey(), 'quantity' => 10, 'unit_cost' => 40000]],
    );

    $response = $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong, ['phone' => '+৮৮০১৭১২-৩৪৫৬৭৮']));

    $order = Order::sole();

    $response->assertCreated()
        ->assertJsonPath('redirect', route('checkout.order-complete', $order->order_number));

    expect(json_decode($response->getCookie(OrderOwnership::COOKIE)->getValue(), true))->toBe([$order->order_number])
        ->and($order->customer_name)->toBe('Rahim Uddin')
        ->and($order->customer_phone)->toBe('+8801712345678')
        ->and($order->customer->is(Customer::sole()))->toBeTrue()
        ->and($order->customer_district)->toBe('Dhaka')
        ->and($order->customer_address)->toBe('House 4, Road 12, Banani')
        ->and($order->shop_name)->toBe('Rahim Tea Stall')
        ->and($order->note)->toBe('Call before coming')
        ->and($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->total_kilograms)->toBe(7)
        ->and($order->subtotal)->toBe(370000)
        ->and($order->delivery_charge)->toBe(15000)
        ->and($order->delivery_label)->not->toBeNull()
        ->and($order->total)->toBe(385000);

    expect($order->lines()->orderBy('id')->get()->map->only(['product_id', 'product_name', 'quantity', 'unit_price', 'mrp', 'unit_cost', 'tier_min', 'tier_max', 'line_total'])->all())->toBe([
        ['product_id' => $premium->getKey(), 'product_name' => 'Premium', 'quantity' => 4, 'unit_price' => 55000, 'mrp' => 65000, 'unit_cost' => 40000, 'tier_min' => 6, 'tier_max' => null, 'line_total' => 220000],
        ['product_id' => $strong->getKey(), 'product_name' => 'Strong', 'quantity' => 3, 'unit_price' => 50000, 'mrp' => 50000, 'unit_cost' => null, 'tier_min' => 1, 'tier_max' => null, 'line_total' => 150000],
    ]);
});

it('records the MRP as it stands at Capture, rather than refusing a quote over an MRP that moved', function () {
    $premium = premium();
    $strong = strong();

    $premium->update(['mrp' => 70000]);

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))->assertCreated();

    expect(Order::sole()->lines()->where('product_id', $premium->getKey())->value('mrp'))->toBe(70000);
});

it('shows the Saving recorded at Capture on the order\'s page, and none where nothing was saved', function () {
    $this->postJson(route('checkout.store'), sevenKilograms(premium(), strong()));
    $saved = Order::sole();

    $this->get(route('checkout.order-complete', $saved->order_number))
        ->assertOk()
        ->assertSee('আপনার সাশ্রয়')
        ->assertSee('৳৪০০');

    $flat = strong(['name' => 'Flat']);
    $this->postJson(route('checkout.store'), checkoutOf([[$flat, 2, 50000]], 12000, 112000));
    $unsaved = Order::query()->whereKeyNot($saved->getKey())->sole();

    $this->get(route('checkout.order-complete', $unsaved->order_number))
        ->assertOk()
        ->assertDontSee('আপনার সাশ্রয়');
});

it('numbers every order BT- and six characters nobody can mishear', function () {
    $premium = premium();
    $strong = strong();

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))->assertCreated();
    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))->assertCreated();

    [$first, $second] = Order::query()->orderBy('id')->pluck('order_number')->all();

    expect($first)->toMatch('/^BT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/')
        ->and($second)->toMatch('/^BT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/')
        ->and($first)->not->toBe($second);
});

/**
 * A Quote that disagrees with the store is refused, never corrected: the
 * courier collects the total on the buyer's screen, so the buyer is shown the
 * figures as they are now before anything is placed.
 */
it('refuses a quote the store no longer agrees with, and answers with the figures as they are now', function (Closure $change, array $now, array $unavailable) {
    $premium = premium();
    $strong = strong();

    $change($premium, $strong);

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))
        ->assertConflict()
        ->assertJsonPath('message', 'দাম বা প্রাপ্যতা বদলেছে। নতুন হিসাব দেখে আবার নিশ্চিত করুন।')
        ->assertJsonPath('current.totalKilograms', $now['totalKilograms'])
        ->assertJsonPath('current.total', $now['total'])
        ->assertJsonPath('unavailable', array_map(fn (string $name): int => ['premium' => $premium, 'strong' => $strong][$name]->getKey(), $unavailable));

    expect(Order::count())->toBe(0)
        ->and(Customer::count())->toBe(0);
})->with([
    'a price moved' => [
        fn (Product $premium) => $premium->update(['tiers' => [['min' => 1, 'max' => 5, 'price' => 60000], ['min' => 6, 'max' => null, 'price' => 56000]]]),
        ['totalKilograms' => 7, 'total' => 389000],
        [],
    ],
    'a product no longer available' => [
        fn (Product $premium, Product $strong) => $strong->update(['is_available' => false]),
        ['totalKilograms' => 4, 'total' => 252000],
        ['strong'],
    ],
    'a product taken back to draft' => [
        fn (Product $premium, Product $strong) => $strong->update(['status' => 'draft']),
        ['totalKilograms' => 4, 'total' => 252000],
        ['strong'],
    ],
    'a product taken out of the catalogue' => [
        fn (Product $premium, Product $strong) => $strong->delete(),
        ['totalKilograms' => 4, 'total' => 252000],
        ['strong'],
    ],
]);

it('refuses a quote whose delivery charge or total the store does not reach', function (int $deliveryCharge, int $total) {
    $premium = premium();
    $strong = strong();

    $this->postJson(route('checkout.store'), checkoutOf([[$premium, 4, 55000], [$strong, 3, 50000]], $deliveryCharge, $total))
        ->assertConflict()
        ->assertJsonPath('current.deliveryCharge', 15000)
        ->assertJsonPath('current.total', 385000);

    expect(Order::count())->toBe(0);
})->with([
    'the delivery charge' => [12000, 382000],
    'the total' => [15000, 384000],
]);

it('refuses a quote that leaves out a line of the cart', function () {
    $premium = premium();
    $strong = strong();

    $payload = sevenKilograms($premium, $strong);
    $payload['quote']['lines'] = [$payload['quote']['lines'][0]];

    $this->postJson(route('checkout.store'), $payload)->assertConflict();

    expect(Order::count())->toBe(0);
});

it('asks for the details an order cannot go without, in Bangla', function () {
    $this->postJson(route('checkout.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name' => 'আপনার নাম লিখুন।',
            'phone' => 'মোবাইল নম্বর লিখুন।',
            'district' => 'জেলা বেছে নিন।',
            'address' => 'ঠিকানা লিখুন।',
            'lines' => 'কার্টে কোনো চা নেই।',
        ]);
});

it('refuses a phone number and a district that cannot be delivered to', function () {
    $premium = premium();
    $strong = strong();

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong, ['phone' => '12345', 'district' => 'Atlantis']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'phone' => 'সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।',
            'district' => 'তালিকা থেকে জেলা বেছে নিন।',
        ]);

    expect(Order::count())->toBe(0);
});

it('takes cash on delivery whatever a single-method checkout posts', function () {
    $this->postJson(route('checkout.store'), sevenKilograms(premium(), strong(), ['payment_method' => 'sslcommerz']))
        ->assertCreated();

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::CashOnDelivery);
});

it('asks how the buyer will pay once there is a choice, and sends an online choice to the gateway', function () {
    $gateway = new SslCommerzScript;
    $gateway->makeUsable();
    $gateway->fakeStart();

    $premium = premium();
    $strong = strong();

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_method' => 'কীভাবে টাকা দেবেন বেছে নিন।']);

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong, ['payment_method' => 'sslcommerz']))
        ->assertCreated()
        ->assertJsonPath('redirect', $gateway->gatewayPageUrl());

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::SslCommerz);
});

it('keeps the checkout throttled', function () {
    app(GeneralSettings::class)->with(['checkoutRateLimit' => 1])->save();

    $premium = premium();
    $strong = strong();

    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))->assertCreated();
    $this->postJson(route('checkout.store'), sevenKilograms($premium, $strong))->assertTooManyRequests();
});

/**
 * The Order Complete Page is addressed by order number, which ends up in
 * history and Referer headers, so only the cookie handed to the placing
 * browser unlocks who the Order is going to (ADR-0014).
 */
it('shows the order to anyone, and who it is going to only to the browser that placed it', function () {
    $response = $this->postJson(route('checkout.store'), sevenKilograms(premium(), strong()));
    $order = Order::sole();
    $address = route('checkout.order-complete', $order->order_number);

    $this->get($address)
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('Premium')
        ->assertSee('৳৩,৮৫০')
        ->assertSee('ক্যাশ অন ডেলিভারি')
        ->assertSee('href="'.url('/my-orders').'"', escape: false)
        ->assertDontSee('Rahim Uddin')
        ->assertDontSee('Banani')
        ->assertDontSee('Rahim Tea Stall');

    $this->withCookie(OrderOwnership::COOKIE, $response->getCookie(OrderOwnership::COOKIE)->getValue())
        ->get($address)
        ->assertOk()
        ->assertSee('Rahim Uddin')
        ->assertSee('Banani')
        ->assertSee('Rahim Tea Stall')
        ->assertSee('Call before coming');
});
