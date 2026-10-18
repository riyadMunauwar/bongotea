<?php

use App\Enums\OrderKind;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Settings\DeliveryChargeSettings;
use Livewire\Livewire;

/**
 * Delivery charged by zone (ADR-0039): the District an Order goes to settles
 * its Delivery Zone, and the Delivery Charge is read from that zone's bands —
 * the zone listing the District, else the everywhere-else one — bulk and
 * retail each from zones of their own. The Order records the zone and band
 * it was charged under, and a District corrected on an unsent Order reprices
 * it.
 *
 * Throughout: bulk Inside Dhaka charges 70.00 and everywhere else 130.00 for
 * any weight; retail Inside Dhaka charges 70.00 up to 2 kg and 90.00 from
 * 3 kg, everywhere else 150.00, and neither is ever free.
 */
beforeEach(function () {
    app(DeliveryChargeSettings::class)->with([
        'bulkZones' => [
            ['name' => 'Inside Dhaka', 'districts' => ['Dhaka'], 'everywhereElse' => false, 'bands' => [['min' => 1, 'max' => null, 'fee' => 7000]]],
            ['name' => 'Outside Dhaka', 'districts' => [], 'everywhereElse' => true, 'bands' => [['min' => 1, 'max' => null, 'fee' => 13000]]],
        ],
        'retailZones' => [
            ['name' => 'Inside Dhaka', 'districts' => ['Dhaka'], 'everywhereElse' => false, 'bands' => [['min' => 1, 'max' => 2, 'fee' => 7000], ['min' => 3, 'max' => null, 'fee' => 9000]], 'freeDeliveryFrom' => null],
            ['name' => 'Outside Dhaka', 'districts' => [], 'everywhereElse' => true, 'bands' => [['min' => 1, 'max' => null, 'fee' => 15000]], 'freeDeliveryFrom' => null],
        ],
    ])->save();
});

/** Two kilograms of a tea at a flat 500.00, quoted with the Delivery Charge shown, to a District. */
function bulkToDistrict(Product $tea, string $district, int $deliveryCharge): array
{
    return [
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'district' => $district,
        'address' => 'House 4, Road 12',
        'cart' => openCart()->getKey(),
        'lines' => [['productId' => $tea->getKey(), 'kilograms' => 2]],
        'quote' => ['lines' => [['productId' => $tea->getKey(), 'tierPrice' => 50000]], 'deliveryCharge' => $deliveryCharge, 'total' => 100000 + $deliveryCharge],
    ];
}

it('charges a bulk Order from the zone its District falls in, and records the zone and band', function (string $district, int $charge, string $label) {
    $tea = Product::factory()->create(['price' => 50000]);

    $this->postJson(route('checkout.store'), bulkToDistrict($tea, $district, $charge))->assertCreated();

    $order = Order::query()->sole();

    expect($order->delivery_charge)->toBe($charge)
        ->and($order->total)->toBe(100000 + $charge)
        ->and($order->delivery_label)->toBe($label);
})->with([
    'a District a zone lists' => ['Dhaka', 7000, 'Inside Dhaka · 1+ kg'],
    'a District no zone lists' => ['Sylhet', 13000, 'Outside Dhaka · 1+ kg'],
]);

it('refuses a Quote carrying another zone\'s Delivery Charge, with the figures for the District given', function () {
    $tea = Product::factory()->create(['price' => 50000]);

    $this->postJson(route('checkout.store'), bulkToDistrict($tea, 'Sylhet', 7000))
        ->assertConflict()
        ->assertJsonPath('current.total', 113000);

    expect(Order::query()->count())->toBe(0);
});

it('charges a packet order by its Packs\' weight rounded up to the next kilogram, in its District\'s retail zone', function () {
    $pack = Pack::factory()->for(Product::factory()->create())->create(['grams' => 250, 'mrp' => 30000, 'price' => 27000]);

    /** Nine 250 g Packs are 2.25 kg, read as 3 kg: Inside Dhaka's 90.00, not its 70.00 for up to 2 kg. */
    $this->postJson(route('checkout.store'), [
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'district' => 'Dhaka',
        'address' => 'House 4, Road 12',
        'cart' => openCart(OrderKind::Retail)->getKey(),
        'packs' => [['packId' => $pack->getKey(), 'productId' => $pack->product_id, 'count' => 9]],
        'quote' => ['lines' => [['packId' => $pack->getKey(), 'packPrice' => 27000]], 'deliveryCharge' => 9000, 'total' => 243000 + 9000],
    ])->assertCreated();

    $order = Order::query()->sole();

    expect($order->delivery_charge)->toBe(9000)
        ->and($order->delivery_label)->toBe('Inside Dhaka · 3+ kg');
});

it('reprices an unsent Order whose District is corrected into another zone, and leaves one whose District is untouched', function () {
    $this->actingAs(User::factory()->workingOrders()->create());
    $tea = Product::factory()->create(['price' => 50000]);
    $order = orderAsCaptured(['district' => 'Dhaka'], [['product' => $tea, 'kilograms' => 2]]);

    expect($order->delivery_charge)->toBe(7000);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_address' => 'House 9, Road 1'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->delivery_charge)->toBe(7000);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_district' => 'Sylhet'])
        ->assertSeeText('Outside Dhaka · 1+ kg')
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->delivery_charge)->toBe(13000)
        ->and($order->total)->toBe(113000)
        ->and($order->delivery_label)->toBe('Outside Dhaka · 1+ kg');
});

it('holds the District shut once an Order has a Shipment, so its charge never reads another zone', function () {
    $this->actingAs(User::factory()->workingOrders()->create());
    $order = orderAsCaptured(['district' => 'Dhaka'], [['product' => Product::factory()->create(['price' => 50000]), 'kilograms' => 2]]);
    Shipment::factory()->returned()->for($order)->create();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->assertFormFieldDisabled('customer_district')
        ->fillForm(['customer_district' => 'Sylhet'])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->customer_district)->toBe('Dhaka')
        ->and($order->delivery_charge)->toBe(7000);
});
