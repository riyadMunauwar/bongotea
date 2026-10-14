<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\Orders\OrderCapture;
use Livewire\Livewire;

/**
 * An Order's lines as a Merchant packs and explains them: Pack weight, packet
 * count, weight, pricing type, the price it was sold at and the line total,
 * all read from what the line recorded at Capture.
 *
 * Assam throughout: ৳500 a kilogram up to 4 kg and ৳480 from 5 kg, in 500 g
 * packets for bulk, and 250 g packets at ৳270 (MRP ৳300) for retail.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    $this->assam = Product::factory()->withoutPacks()->create(['name' => 'Assam', 'mrp' => 52000, 'tiers' => [
        ['min' => 1, 'max' => 4, 'price' => 50000],
        ['min' => 5, 'max' => null, 'price' => 48000],
    ]]);
    $this->halfKilo = Pack::factory()->bulkOnly()->for($this->assam)->create(['grams' => 500]);
    $this->quarter = Pack::factory()->retailOnly()->for($this->assam)->create(['grams' => 250, 'mrp' => 30000, 'price' => 27000]);
    $this->assam->update(['default_bulk_pack_id' => $this->halfKilo->getKey()]);
});

/**
 * @return array<string, mixed>
 */
function figuresDeliveryDetails(): array
{
    return [
        'name' => 'Rahim',
        'phone' => '01712345678',
        'address' => 'Banani',
        'district' => 'Dhaka',
        'payment_method' => PaymentMethod::CashOnDelivery,
    ];
}

function bulkOrderOfTwoKilos(Product $product, Pack $pack): Order
{
    return app(OrderCapture::class)->capture([['product' => $product, 'kilograms' => 2, 'pack' => $pack]], figuresDeliveryDetails());
}

function retailOrderOfFour(Pack $pack): Order
{
    return app(OrderCapture::class)->captureRetail(
        [['packId' => $pack->getKey(), 'productId' => $pack->product_id, 'pack' => $pack, 'count' => 4]],
        figuresDeliveryDetails(),
    );
}

it('shows a bulk line\'s Pack, packets, weight, pricing type, Tier Price per kilogram and total', function () {
    $order = bulkOrderOfTwoKilos($this->assam, $this->halfKilo);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeHtmlInOrder(['Assam', '500 g', '4 PCS', '2,000 g / 2 kg', 'Bulk', Money::format(50000).' / kg', 'Tier Price, 1–4 kg', Money::format(100000)]);
});

it('shows a retail line\'s Pack, packets, weight, pricing type, Pack Price and total', function () {
    $order = retailOrderOfFour($this->quarter);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeHtmlInOrder(['Assam', '250 g', '4 PCS', '1,000 g / 1 kg', 'Retail', Money::format(27000).' / pack', Money::format(108000)]);
});

it('keeps showing what a line recorded at Capture after the Product\'s Packs, Pack Prices and Tiers change', function () {
    $bulk = bulkOrderOfTwoKilos($this->assam, $this->halfKilo);
    $retail = retailOrderOfFour($this->quarter);

    $this->assam->update(['tiers' => [['min' => 1, 'max' => null, 'price' => 40000]]]);
    $this->quarter->update(['grams' => 200, 'mrp' => 20000, 'price' => 19000]);
    $this->halfKilo->update(['grams' => 400]);

    Livewire::test(ViewOrder::class, ['record' => $bulk->getKey()])
        ->assertSeeHtmlInOrder(['500 g', '4 PCS', '2,000 g / 2 kg', 'Bulk', Money::format(50000).' / kg', 'Tier Price, 1–4 kg', Money::format(100000)]);

    Livewire::test(ViewOrder::class, ['record' => $retail->getKey()])
        ->assertSeeHtmlInOrder(['250 g', '4 PCS', '1,000 g / 1 kg', 'Retail', Money::format(27000).' / pack', Money::format(108000)]);
});

it('keeps a line\'s Pack weight and packets once the Pack itself is removed', function () {
    $order = bulkOrderOfTwoKilos($this->assam, $this->halfKilo);

    $this->assam->update(['default_bulk_pack_id' => null]);
    $this->halfKilo->delete();

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeHtmlInOrder(['500 g', '4 PCS', '2,000 g / 2 kg']);
});
