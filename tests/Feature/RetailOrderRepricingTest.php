<?php

use App\Enums\OrderKind;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\Orders\RetailRepricing;
use App\Support\Settings\DeliveryChargeSettings;
use Livewire\Livewire;

/**
 * Staff taking and changing retail Orders (ADR-0034): Packs and counts,
 * priced at today's Pack Prices with the flat retail Delivery Charge — 60.00
 * throughout, free from 1,000.00 — and nothing from the Tiers.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    app(DeliveryChargeSettings::class)->with(['retailDeliveryCharge' => 6000, 'retailFreeDeliveryFrom' => 100000])->save();
});

function quarterKiloOfAssam(array $attributes = []): Pack
{
    return Pack::factory()
        ->for(Product::factory()->create(['name' => 'Assam']))
        ->create(['grams' => 250, 'mrp' => 30000, 'price' => 27000, ...$attributes]);
}

/**
 * A retail Order as Capture left it: one Pack line at the Pack Price agreed then.
 */
function retailOrderAgreedAt(Pack $pack, int $count, int $price): Order
{
    $order = Order::factory()->retail()->create(['customer_district' => 'Dhaka', 'delivery_charge' => 6000]);
    OrderLine::factory()->for($order)->ofPack($pack, $count)->create(['unit_price' => $price]);

    return $order->refresh();
}

it('takes a retail order by phone, its lines chosen from orderable Packs and priced at their Pack Prices', function () {
    $assam = quarterKiloOfAssam();
    $switchedOff = quarterKiloOfAssam(['grams' => 100, 'mrp' => 12000, 'price' => 12000, 'is_available' => false]);

    Livewire::test(CreateOrder::class)
        ->fillForm(['kind' => OrderKind::Retail->value, 'customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
        ->set('data.lines', ['a' => ['pack_id' => $switchedOff->getKey(), 'count' => 1]])
        ->call('create')
        ->assertHasFormErrors(['lines.a.pack_id']);

    Livewire::test(CreateOrder::class)
        ->fillForm(['kind' => OrderKind::Retail->value, 'customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
        ->set('data.lines', ['a' => ['pack_id' => $assam->getKey(), 'count' => 2]])
        ->assertSeeText(Money::format(60000))
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->sole();

    expect($order->kind)->toBe(OrderKind::Retail)
        ->and($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->subtotal)->toBe(54000)
        ->and($order->delivery_charge)->toBe(6000)
        ->and($order->total)->toBe(60000)
        ->and($order->lines()->sole()->only(['pack_id', 'pack_grams', 'quantity', 'unit_price', 'mrp', 'tier_min']))->toBe([
            'pack_id' => $assam->getKey(),
            'pack_grams' => 250,
            'quantity' => 2,
            'unit_price' => 27000,
            'mrp' => 30000,
            'tier_min' => null,
        ]);
});

it('takes a bulk order by phone as before when bulk is chosen', function () {
    $tea = Product::factory()->create(['price' => 30000]);

    Livewire::test(CreateOrder::class)
        ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
        ->set('data.lines', ['a' => ['product_id' => $tea->getKey(), 'kilograms' => 2]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Order::query()->sole()->kind)->toBe(OrderKind::Bulk);
});

it('reprices an edited retail order at today\'s Pack Prices and retail charge, showing was and now', function () {
    $assam = quarterKiloOfAssam();
    $order = retailOrderAgreedAt($assam, 2, 25000);

    expect($order->total)->toBe(56000);

    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
    $key = array_key_first($component->get('data.lines'));

    $component->set("data.lines.{$key}.count", 4)
        ->assertSeeText('was '.Money::format(56000).' → now '.Money::format(108000))
        ->call('save')
        ->assertHasNoFormErrors();

    /** Four at today's 270.00 is 1,080.00, past the threshold, so delivery is free. */
    expect($order->refresh()->only(['subtotal', 'delivery_charge', 'total']))->toBe(['subtotal' => 108000, 'delivery_charge' => 0, 'total' => 108000])
        ->and($order->lines()->sole()->unit_price)->toBe(27000)
        ->and($order->kind)->toBe(OrderKind::Retail);
});

it('keeps the prices agreed at Capture when only a retail order\'s details change', function () {
    $order = retailOrderAgreedAt(quarterKiloOfAssam(), 2, 25000);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_name' => 'Karim'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->total)->toBe(56000);
});

it('sets a Pack Price by hand with a reason, naming who gave it', function () {
    $negotiator = User::factory()->overridingPrices()->create();
    $this->actingAs($negotiator);

    $order = retailOrderAgreedAt(quarterKiloOfAssam(), 2, 27000);

    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
    $key = array_key_first($component->get('data.lines'));

    $component->set("data.lines.{$key}.override_price", '250')
        ->set("data.lines.{$key}.override_reason", 'Regular at the market')
        ->call('save')
        ->assertHasNoFormErrors();

    $line = $order->refresh()->lines()->sole();

    expect($line->unit_price)->toBe(25000)
        ->and($line->price_override_reason)->toBe('Regular at the market')
        ->and($line->price_overridden_by)->toBe($negotiator->getKey())
        ->and($order->activitiesAsSubject()->where('event', 'price_overridden')->sole()->causer_id)->toBe($negotiator->getKey());
});

it('refuses a Pack Price set by hand above that Pack\'s MRP', function () {
    $this->actingAs(User::factory()->overridingPrices()->create());
    $order = retailOrderAgreedAt(quarterKiloOfAssam(), 2, 27000);

    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
    $key = array_key_first($component->get('data.lines'));

    $component->set("data.lines.{$key}.override_price", '301')
        ->set("data.lines.{$key}.override_reason", 'Special')
        ->call('save')
        ->assertHasFormErrors(["lines.{$key}.override_price"]);

    expect($order->refresh()->lines()->sole()->isPriceOverridden())->toBeFalse();
});

it('offers no way to change an order\'s kind once it is taken', function () {
    $order = retailOrderAgreedAt(quarterKiloOfAssam(), 2, 27000);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->assertFormFieldDoesNotExist('kind')
        ->set('data.kind', OrderKind::Bulk->value)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->kind)->toBe(OrderKind::Retail);
});

it('shows each Pack line\'s weight and count on the order', function () {
    $order = retailOrderAgreedAt(quarterKiloOfAssam(), 2, 27000);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText('2 × 250 g');
});

it('refuses to put a Pack that cannot be ordered now on a retail order, however the line is sent', function () {
    $order = retailOrderAgreedAt(quarterKiloOfAssam(), 2, 27000);
    $switchedOff = quarterKiloOfAssam(['grams' => 100, 'mrp' => 12000, 'price' => 12000, 'is_available' => false]);

    app(RetailRepricing::class)->apply($order, [['pack_id' => $switchedOff->getKey(), 'count' => 1]], auth()->user());
})->throws(InvalidArgumentException::class);
