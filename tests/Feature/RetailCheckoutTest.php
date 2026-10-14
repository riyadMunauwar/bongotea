<?php

use App\Enums\OrderKind;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Settings\DeliveryChargeSettings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Checkout opened for the packet basket (ADR-0034): the browser sends
 * the basket's Packs and the retail Quote it showed — each Pack Price, the
 * retail Delivery Charge and the total — and Capture prices them again
 * through retail pricing. A Quote that disagrees, or a Pack that can no
 * longer be ordered, is refused with the figures as they are now, and
 * nothing is written.
 *
 * Throughout: a 60.00 retail Delivery Charge, free from 1,000.00, and Assam
 * in a 250 g Pack at 270.00 against an MRP of 300.00, so two of them are
 * 540.00 and 600.00 with delivery.
 */
beforeEach(function () {
    app(DeliveryChargeSettings::class)->with(['retailDeliveryCharge' => 6000, 'retailFreeDeliveryFrom' => 100000])->save();
});

function assamPack(array $attributes = []): Pack
{
    return Pack::factory()
        ->for(Product::factory()->create(['name' => 'Assam']))
        ->create(['grams' => 250, 'mrp' => 30000, 'price' => 27000, ...$attributes]);
}

/**
 * What the checkout page posts for the packet basket.
 *
 * @param  list<array{0: Pack, 1: int, 2: int}>  $lines  Pack, count, the Pack Price shown
 * @param  array<string, mixed>  $details
 * @return array<string, mixed>
 */
function retailCheckoutOf(array $lines, int $deliveryCharge, int $total, array $details = []): array
{
    return [
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'district' => 'Dhaka',
        'address' => 'House 4, Road 12, Banani',
        'cart' => 'retail',
        'packs' => array_map(fn (array $line): array => ['packId' => $line[0]->getKey(), 'productId' => $line[0]->product_id, 'count' => $line[1]], $lines),
        'quote' => [
            'lines' => array_map(fn (array $line): array => ['packId' => $line[0]->getKey(), 'packPrice' => $line[2]], $lines),
            'deliveryCharge' => $deliveryCharge,
            'total' => $total,
        ],
        ...$details,
    ];
}

it('opens the Checkout for the packet basket when its link names it, and for the bulk Cart otherwise', function () {
    $this->get(route('checkout', ['cart' => 'retail']))->assertInertia(fn (Assert $page) => $page->component('checkout')->where('cart', 'retail'));
    $this->get(route('checkout'))->assertInertia(fn (Assert $page) => $page->where('cart', 'bulk'));
    $this->get(route('checkout', ['cart' => 'nonsense']))->assertInertia(fn (Assert $page) => $page->where('cart', 'bulk'));
});

it('captures the packet basket as a retail Order of Pack lines, stamping each line\'s weight, prices and cost', function () {
    $pack = assamPack();

    app(StockLedger::class)->recordSupply(
        supplier: Supplier::factory()->create(),
        suppliedOn: now()->toDateString(),
        lines: [['product_id' => $pack->product_id, 'quantity' => 10000, 'unit_cost' => 40000]],
    );

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 27000]], 6000, 60000))->assertCreated();

    $order = Order::sole();

    expect($order->kind)->toBe(OrderKind::Retail)
        ->and($order->subtotal)->toBe(54000)
        ->and($order->delivery_charge)->toBe(6000)
        ->and($order->delivery_label)->toBe('Retail delivery')
        ->and($order->total)->toBe(60000)
        ->and($order->total_kilograms)->toBe(0)
        ->and($order->lines->sole()->only(['product_id', 'category_id', 'pack_id', 'pack_grams', 'product_name', 'quantity', 'unit_price', 'mrp', 'unit_cost', 'tier_min', 'tier_max', 'line_total']))->toBe([
            'product_id' => $pack->product_id,
            'category_id' => $pack->product->category_id,
            'pack_id' => $pack->id,
            'pack_grams' => 250,
            'product_name' => 'Assam',
            'quantity' => 2,
            'unit_price' => 27000,
            'mrp' => 30000,
            /** A quarter of a kilogram at 400.00 a kilogram. */
            'unit_cost' => 10000,
            'tier_min' => null,
            'tier_max' => null,
            'line_total' => 54000,
        ]);
});

it('delivers free once the subtotal reaches the threshold', function () {
    $pack = assamPack(['mrp' => 55000, 'price' => 50000]);

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 50000]], 0, 100000))->assertCreated();

    expect(Order::sole()->only(['delivery_charge', 'total']))->toBe(['delivery_charge' => 0, 'total' => 100000]);
});

it('touches no stock at Capture', function () {
    $pack = assamPack();

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 27000]], 6000, 60000))->assertCreated();

    expect(StockMovement::query()->exists())->toBeFalse();
});

it('keeps a retail line whole when its Pack is deleted afterwards', function () {
    $pack = assamPack();

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 27000]], 6000, 60000))->assertCreated();

    $pack->delete();

    expect(Order::sole()->lines->sole()->only(['pack_id', 'pack_grams', 'line_total']))->toBe(['pack_id' => null, 'pack_grams' => 250, 'line_total' => 54000]);
});

it('refuses a Quote whose Pack Price has moved, with the basket as it is now, and writes nothing', function () {
    $pack = assamPack();

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 26000]], 6000, 58000))
        ->assertConflict()
        ->assertJsonPath('current.total', 60000)
        ->assertJsonPath('current.lines.0.packPrice', 27000)
        ->assertJsonPath('unavailable', []);

    expect(Order::query()->exists())->toBeFalse();
});

it('refuses a Pack that can no longer be ordered, naming it and pricing the rest', function (Closure $spoil) {
    $assam = assamPack();
    $other = Pack::factory()->for(Product::factory())->create(['grams' => 100, 'mrp' => 12000, 'price' => 12000]);

    $line = $spoil($assam);

    $this->postJson(route('checkout.store'), retailCheckoutOf([$line, [$other, 1, 12000]], 6000, 72000))
        ->assertConflict()
        ->assertJsonPath('unavailable', [$assam->id])
        ->assertJsonPath('current.total', 18000);

    expect(Order::query()->exists())->toBeFalse();
})->with([
    'switched off' => [function (Pack $pack): array {
        $pack->update(['is_available' => false]);

        return [$pack, 2, 27000];
    }],
    'its Product unavailable' => [function (Pack $pack): array {
        $pack->product->update(['is_available' => false]);

        return [$pack, 2, 27000];
    }],
    'removed' => [function (Pack $pack): array {
        $pack->delete();

        return [$pack, 2, 27000];
    }],
    'ticked পাইকারি only' => [function (Pack $pack): array {
        $pack->update(['serves_retail' => false]);

        return [$pack, 2, 27000];
    }],
    'its Product no longer sold খুচরা' => [function (Pack $pack): array {
        $pack->product->update(['sold_retail' => false]);

        return [$pack, 2, 27000];
    }],
    'named under another Product' => [function (Pack $pack): array {
        $pack->product_id = Product::factory()->create()->id;

        return [$pack, 2, 27000];
    }],
]);

it('refuses a request that mixes Kilogram and Pack lines', function () {
    $pack = assamPack();
    $tea = Product::factory()->create();

    $this->postJson(route('checkout.store'), [
        ...retailCheckoutOf([[$pack, 1, 27000]], 6000, 33000),
        'lines' => [['productId' => $tea->id, 'kilograms' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines');

    $this->postJson(route('checkout.store'), [
        ...retailCheckoutOf([[$pack, 1, 27000]], 6000, 33000),
        'cart' => 'bulk',
        'lines' => [['productId' => $tea->id, 'kilograms' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('packs');

    expect(Order::query()->exists())->toBeFalse();
});

it('shows Pack lines on the Order Complete Page by their weight and count', function () {
    $pack = assamPack();

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 27000]], 6000, 60000))->assertCreated();

    $this->get(route('checkout.order-complete', Order::sole()->order_number))
        ->assertOk()
        ->assertSee('Assam, ২৫০ গ্রাম × ২')
        ->assertSee('মোট প্যাকেট')
        ->assertDontSee('মোট পরিমাণ');
});

it('lists a retail Order\'s Pack lines in My Orders by weight and count', function () {
    $pack = assamPack();

    $this->postJson(route('checkout.store'), retailCheckoutOf([[$pack, 2, 27000]], 6000, 60000))->assertCreated();

    Page::factory()->published()->withBlocks([['type' => 'my_orders', 'data' => []]])->create(['slug' => 'my-orders']);
    signInAs('01712345678');

    $this->get('/my-orders')->assertInertia(fn (Assert $page) => $page
        ->where('page.blocks.0.props.orders.0.lines', [['name' => 'Assam', 'packGrams' => 250, 'count' => 2]])
    );
});

it('refuses to change an Order\'s kind after Capture', function () {
    $order = Order::factory()->create();

    $order->update(['kind' => OrderKind::Retail]);
})->throws(LogicException::class);

it('shows each Order\'s kind on the Orders queue and filters by it', function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    $bulk = Order::factory()->create();
    $retail = Order::factory()->retail()->create();

    Livewire\Livewire::test(ListOrders::class)
        ->assertTableColumnStateSet('kind', OrderKind::Retail, $retail)
        ->filterTable('kind', OrderKind::Retail->value)
        ->assertCanSeeTableRecords([$retail])
        ->assertCanNotSeeTableRecords([$bulk]);
});
