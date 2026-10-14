<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Money;
use App\Support\Orders\Repricing;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Delivery Bands are the Pricing defaults throughout: 1–5 kg ৳120, 6–10 kg
| ৳150, 11–20 kg ৳200.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->workingOrders()->create());
});

/**
 * A tea breaking at 6 kg: ৳500 a kg up to five, ৳450 from six.
 */
function teaBreakingAtSix(string $name = 'Premium'): Product
{
    return Product::factory()->create(['name' => $name, 'tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 50000],
        ['min' => 6, 'max' => null, 'price' => 45000],
    ]]);
}

/**
 * An Order as Capture left it: each line at the price agreed then, which the
 * Products may no longer charge.
 *
 * @param  list<array{product: Product, kilograms: int, price: int}>  $lines
 */
function orderAgreedAt(array $lines, array $attributes = []): Order
{
    $order = Order::factory()->create(['customer_district' => 'Dhaka', 'delivery_charge' => 12000, ...$attributes]);

    foreach ($lines as $line) {
        OrderLine::factory()->for($order)->for($line['product'])->create([
            'product_name' => $line['product']->name,
            'unit_price' => $line['price'],
            'quantity' => $line['kilograms'],
        ]);
    }

    return $order->refresh();
}

/**
 * The Order's editor with each line's row changed in place, keeping the rows'
 * keys so Filament reads it as the same lines edited rather than replaced.
 *
 * A row the change answers null for is removed.
 *
 * @param  callable(array<string, mixed>, OrderLine): (array<string, mixed>|null)  $change
 * @param  array<string, array<string, mixed>>  $added
 */
function changingLines(Order $order, callable $change, array $added = []): Testable
{
    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
    $lines = [];

    foreach ($component->get('data.lines') as $key => $row) {
        $lines[$key] = $change($row, OrderLine::find($row['id']));
    }

    /** Set rather than filled: filling merges, which would keep a removed row. */
    return $component->set('data.lines', [...array_filter($lines), ...$added]);
}

it('prices an order taken by phone with the Tiers and Delivery Bands, live, before it is saved', function () {
    $premium = teaBreakingAtSix();
    $everyday = Product::factory()->create(['name' => 'Everyday', 'price' => 30000]);

    /**
     * 4 + 3 = 7 kg puts Premium in its 6+ Tier although its own line is 4 kg:
     * 4 × ৳450 + 3 × ৳300 = ৳2,700, and ৳150 delivery for 6–10 kg.
     */
    $component = Livewire::test(CreateOrder::class)
        ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
        ->set('data.lines', [
            'a' => ['product_id' => $premium->getKey(), 'kilograms' => 4],
            'b' => ['product_id' => $everyday->getKey(), 'kilograms' => 3],
        ])
        ->assertSeeText(Money::format(45000))
        ->assertSeeText(Money::format(285000));

    expect(Order::query()->count())->toBe(0);

    $component->call('create')->assertHasNoFormErrors();

    $order = Order::query()->sole();

    expect($order->subtotal)->toBe(270000)
        ->and($order->delivery_charge)->toBe(15000)
        ->and($order->total)->toBe(285000)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->customer->phone)->toBe($order->customer_phone)
        ->and($order->lines()->where('product_id', $premium->getKey())->sole())
        ->unit_price->toBe(45000)
        ->tier_min->toBe(6)
        ->tier_max->toBeNull();
});

it('refuses a phone order with no lines', function () {
    Livewire::test(CreateOrder::class)
        ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
        ->set('data.lines', [])
        ->call('create')
        ->assertHasFormErrors(['lines']);

    expect(Order::query()->count())->toBe(0);
});

it('reprices the whole order at today\'s prices when a line changes, and shows was and now before saving', function () {
    $premium = teaBreakingAtSix();
    $everyday = Product::factory()->create(['name' => 'Everyday', 'price' => 30000]);

    /** Captured at 3 + 1 kg, Premium at ৳480 then: ৳1,440 + ৳280 + ৳120 = ৳1,840. */
    $order = orderAgreedAt([
        ['product' => $premium, 'kilograms' => 3, 'price' => 48000],
        ['product' => $everyday, 'kilograms' => 1, 'price' => 28000],
    ]);

    expect($order->total)->toBe(184000);

    /**
     * Everyday goes to 3 kg: 6 kg in all, so Premium drops to its 6+ Tier at
     * today's ৳450 and Everyday is today's ৳300 — 3 × ৳450 + 3 × ৳300 + ৳150.
     */
    $component = changingLines($order, fn (array $row, OrderLine $line): array => $line->product_id === $everyday->getKey()
        ? [...$row, 'kilograms' => 3]
        : $row)
        ->assertSeeText('was '.Money::format(184000).' → now '.Money::format(240000));

    expect($order->refresh()->total)->toBe(184000);

    $component->call('save')->assertHasNoFormErrors();

    $order->refresh();

    expect($order->total)->toBe(240000)
        ->and($order->delivery_charge)->toBe(15000)
        ->and($order->lines()->where('product_id', $premium->getKey())->sole()->unit_price)->toBe(45000)
        ->and($order->activitiesAsSubject()->where('event', 'repriced')->sole()->properties->all())
        ->toBe(['was' => 184000, 'now' => 240000]);
});

it('keeps the prices agreed at Capture when only the order\'s details change', function () {
    $premium = teaBreakingAtSix();
    $order = orderAgreedAt([['product' => $premium, 'kilograms' => 2, 'price' => 48000]]);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->assertDontSeeText('was ')
        ->fillForm(['status' => OrderStatus::Confirmed->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->lines()->sole()->unit_price)->toBe(48000)
        ->and($order->total)->toBe(108000);
});

it('adds and removes lines, repricing what is left', function () {
    $premium = teaBreakingAtSix();
    $everyday = Product::factory()->create(['name' => 'Everyday', 'price' => 30000]);
    $strong = Product::factory()->create(['name' => 'Strong', 'price' => 40000]);
    $order = orderAgreedAt([
        ['product' => $premium, 'kilograms' => 2, 'price' => 50000],
        ['product' => $everyday, 'kilograms' => 1, 'price' => 30000],
    ]);

    changingLines(
        $order,
        fn (array $row, OrderLine $line): ?array => $line->product_id === $everyday->getKey() ? null : $row,
        ['added' => ['product_id' => $strong->getKey(), 'kilograms' => 4]],
    )->call('save')->assertHasNoFormErrors();

    /** 2 + 4 = 6 kg: Premium at ৳450, Strong at ৳400, ৳150 delivery. */
    expect($order->refresh()->lines()->pluck('unit_price', 'product_id')->all())
        ->toEqual([$premium->getKey() => 45000, $strong->getKey() => 40000])
        ->and($order->total)->toBe(2 * 45000 + 4 * 40000 + 15000);
});

it('locks the lines of an order that has any Shipment', function () {
    $premium = teaBreakingAtSix();
    $order = orderAgreedAt([['product' => $premium, 'kilograms' => 2, 'price' => 48000]]);
    Shipment::factory()->returned()->for($order)->create();

    changingLines($order, fn (array $row): array => [...$row, 'kilograms' => 9])
        ->assertSeeText('Locked')
        ->call('save')
        ->assertHasNoFormErrors();

    $line = $order->refresh()->lines()->sole();

    expect($line->quantity)->toBe(2)
        ->and($line->unit_price)->toBe(48000)
        ->and($order->total)->toBe(108000);
});

it('sets a price by hand with a reason, naming who gave it on the order\'s trail', function () {
    $negotiator = User::factory()->overridingPrices()->create();
    $this->actingAs($negotiator);

    $premium = teaBreakingAtSix();
    $order = orderAgreedAt([['product' => $premium, 'kilograms' => 2, 'price' => 50000]]);

    changingLines($order, fn (array $row): array => [...$row, 'override_price' => '420', 'override_reason' => 'Agreed with the hotel for the season'])
        ->call('save')
        ->assertHasNoFormErrors();

    $line = $order->refresh()->lines()->sole();
    $trail = $order->activitiesAsSubject()->where('event', 'price_overridden')->sole();

    expect($line->unit_price)->toBe(42000)
        ->and($line->price_override_reason)->toBe('Agreed with the hotel for the season')
        ->and($line->price_overridden_by)->toBe($negotiator->getKey())
        ->and($line->tier_min)->toBeNull()
        ->and($trail->causer_id)->toBe($negotiator->getKey())
        ->and($trail->properties['reason'])->toBe('Agreed with the hotel for the season');

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText("Set by hand by {$negotiator->name}: Agreed with the hotel for the season");
});

it('takes a line as heavy as Checkout takes and refuses one a kilogram heavier', function () {
    $order = orderAgreedAt([['product' => teaBreakingAtSix(), 'kilograms' => 2, 'price' => 50000]]);

    changingLines($order, fn (array $row): array => [...$row, 'kilograms' => CheckoutRequest::MAX_KILOGRAMS])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->lines()->sole()->quantity)->toBe(CheckoutRequest::MAX_KILOGRAMS);

    $component = changingLines($order, fn (array $row): array => [...$row, 'kilograms' => CheckoutRequest::MAX_KILOGRAMS + 1])
        ->call('save');

    $key = array_key_first($component->get('data.lines'));
    $component->assertHasFormErrors(["lines.{$key}.kilograms"]);
});

it('refuses a price set by hand with no reason', function () {
    $this->actingAs(User::factory()->overridingPrices()->create());
    $order = orderAgreedAt([['product' => teaBreakingAtSix(), 'kilograms' => 2, 'price' => 50000]]);

    $component = changingLines($order, fn (array $row): array => [...$row, 'override_price' => '420', 'override_reason' => ''])
        ->call('save');

    $key = array_key_first($component->get('data.lines'));
    $component->assertHasFormErrors(["lines.{$key}.override_reason"]);

    expect($order->refresh()->lines()->sole()->unit_price)->toBe(50000);
});

it('refuses a price set by hand above the tea\'s MRP', function () {
    $this->actingAs(User::factory()->overridingPrices()->create());
    $order = orderAgreedAt([['product' => teaBreakingAtSix(), 'kilograms' => 2, 'price' => 50000]]);

    $component = changingLines($order, fn (array $row): array => [...$row, 'override_price' => '501', 'override_reason' => 'Special blend'])
        ->call('save');

    $key = array_key_first($component->get('data.lines'));
    $component->assertHasFormErrors(["lines.{$key}.override_price"]);

    expect($order->refresh()->lines()->sole()->isPriceOverridden())->toBeFalse();
});

it('flags a price set by hand that the MRP has since dropped below, rather than repricing over it', function (Closure $actingAs, string $flagged) {
    $this->actingAs(User::factory()->overridingPrices()->create());

    $premium = teaBreakingAtSix();
    $house = teaBreakingAtSix('House');
    $order = orderAgreedAt([
        ['product' => $premium, 'kilograms' => 3, 'price' => 50000],
        ['product' => $house, 'kilograms' => 2, 'price' => 50000],
    ]);

    changingLines($order, fn (array $row, OrderLine $line): array => $line->product_id === $premium->getKey()
        ? [...$row, 'override_price' => '480', 'override_reason' => 'Regular since 2019']
        : $row)->call('save')->assertHasNoFormErrors();

    $premium->update(['mrp' => 47000, 'tiers' => [['min' => 1, 'max' => 5, 'price' => 47000], ['min' => 6, 'max' => null, 'price' => 45000]]]);

    $this->actingAs($actingAs());

    $component = changingLines($order, fn (array $row, OrderLine $line): array => $line->product_id === $house->getKey()
        ? [...$row, 'kilograms' => 3]
        : $row)->call('save');

    $premiumKey = collect($component->get('data.lines'))->search(fn (array $row): bool => (int) $row['product_id'] === $premium->getKey());
    $component->assertHasFormErrors(["lines.{$premiumKey}.{$flagged}"]);

    expect($order->refresh()->lines()->where('product_id', $house->getKey())->sole()->quantity)->toBe(2);
})->with([
    'by a User who may set prices' => [fn () => User::factory()->overridingPrices()->create(), 'override_price'],
    'by a User who may not' => [fn () => User::factory()->workingOrders()->create(), 'product_id'],
]);

it('saves the details of an order whose price set by hand the MRP has since dropped below', function () {
    $this->actingAs(User::factory()->overridingPrices()->create());

    $premium = teaBreakingAtSix();
    $order = orderAgreedAt([['product' => $premium, 'kilograms' => 3, 'price' => 50000]]);

    changingLines($order, fn (array $row): array => [...$row, 'override_price' => '480', 'override_reason' => 'Regular since 2019'])
        ->call('save')->assertHasNoFormErrors();

    $premium->update(['mrp' => 47000, 'tiers' => [['min' => 1, 'max' => null, 'price' => 47000]]]);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_address' => 'House 9, Road 1'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->customer_address)->toBe('House 9, Road 1')
        ->and($order->lines()->sole()->unit_price)->toBe(48000);
});

it('shows the Saving each line recorded against its MRP', function () {
    $order = orderAgreedAt([['product' => teaBreakingAtSix(), 'kilograms' => 3, 'price' => 45000]]);
    $order->lines()->sole()->update(['mrp' => 52000]);

    expect($order->refresh()->savingShown())->toBe(21000);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText('Saving')
        ->assertSeeText(Money::format(21000));
});

it('gives no way to set a price by hand without the Permission', function () {
    $order = orderAgreedAt([['product' => teaBreakingAtSix(), 'kilograms' => 2, 'price' => 50000]]);

    $component = changingLines($order, fn (array $row): array => [...$row, 'kilograms' => 3, 'override_price' => '1', 'override_reason' => 'Mates rates']);

    $component->assertFormFieldHidden('lines.'.array_key_first($component->get('data.lines')).'.override_price')
        ->call('save')
        ->assertHasNoFormErrors();

    $line = $order->refresh()->lines()->sole();

    expect($line->unit_price)->toBe(50000)
        ->and($line->quantity)->toBe(3)
        ->and($line->isPriceOverridden())->toBeFalse();
});

it('keeps a price set by hand through later Repricing, still counting its Kilograms', function () {
    $this->actingAs(User::factory()->overridingPrices()->create());

    $premium = teaBreakingAtSix();
    $house = teaBreakingAtSix('House');
    $order = orderAgreedAt([
        ['product' => $premium, 'kilograms' => 3, 'price' => 50000],
        ['product' => $house, 'kilograms' => 2, 'price' => 50000],
    ]);

    changingLines($order, fn (array $row, OrderLine $line): array => $line->product_id === $premium->getKey()
        ? [...$row, 'override_price' => '400', 'override_reason' => 'Regular since 2019']
        : $row)->call('save')->assertHasNoFormErrors();

    /**
     * A User who may not override now takes House from 2 kg to 3: 6 kg in
     * all, so House drops to ৳450 — counted with Premium's 3 kg — while
     * Premium stays at the ৳400 agreed.
     */
    $this->actingAs(User::factory()->workingOrders()->create());

    changingLines($order, fn (array $row, OrderLine $line): array => $line->product_id === $house->getKey()
        ? [...$row, 'kilograms' => 3]
        : $row)->call('save')->assertHasNoFormErrors();

    $prices = $order->refresh()->lines()->pluck('unit_price', 'product_id');

    expect($prices[$premium->getKey()])->toBe(40000)
        ->and($prices[$house->getKey()])->toBe(45000)
        ->and($order->lines()->where('product_id', $premium->getKey())->sole()->isPriceOverridden())->toBeTrue()
        ->and($order->delivery_charge)->toBe(15000);
});

it('leaves an order holding a price set by hand unrepriced when a User without the Permission saves only its details', function () {
    $premium = teaBreakingAtSix();
    $house = teaBreakingAtSix('House');
    $order = orderAgreedAt([
        ['product' => $premium, 'kilograms' => 1, 'price' => 40000],
        ['product' => $house, 'kilograms' => 1, 'price' => 48000],
    ]);
    $order->lines()->where('product_id', $premium->getKey())->sole()->update(['price_override_reason' => 'Trial price']);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['status' => OrderStatus::Confirmed->value])
        ->call('save')
        ->assertHasNoFormErrors();

    /** House stays at the ৳480 agreed rather than today's ৳500. */
    expect($order->refresh()->lines()->where('product_id', $house->getKey())->sole()->unit_price)->toBe(48000)
        ->and($order->activitiesAsSubject()->where('event', 'repriced')->exists())->toBeFalse();
});

it('drops a price set by hand when its line is switched to another tea', function () {
    $this->actingAs(User::factory()->overridingPrices()->create());

    $premium = teaBreakingAtSix();
    $everyday = Product::factory()->create(['name' => 'Everyday', 'price' => 30000]);
    $order = orderAgreedAt([['product' => $premium, 'kilograms' => 2, 'price' => 40000]]);
    $order->lines()->sole()->update(['price_override_reason' => 'Agreed for Premium']);

    changingLines($order, fn (array $row): array => [...$row, 'product_id' => $everyday->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    $line = $order->refresh()->lines()->sole();

    expect($line->product_id)->toBe($everyday->getKey())
        ->and($line->unit_price)->toBe(30000)
        ->and($line->isPriceOverridden())->toBeFalse();
});

it('finds an order by part of its number', function () {
    $wanted = Order::factory()->create(['order_number' => 'BT-7K3QXA']);
    $other = Order::factory()->create(['order_number' => 'BT-9ZZZZZ']);

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->searchTable('7k3q')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('refuses a bulk line whose Product is no longer sold পাইকারি, even one the Order holds', function () {
    $premium = teaBreakingAtSix();
    $order = orderAgreedAt([['product' => $premium, 'kilograms' => 3, 'price' => 50000]]);
    $premium->update(['sold_bulk' => false, 'sold_retail' => true]);

    app(Repricing::class)->apply($order, [['id' => $order->lines()->sole()->getKey(), 'product_id' => $premium->getKey(), 'kilograms' => 4]], auth()->user());
})->throws(InvalidArgumentException::class);

it('offers a bulk line only Products sold পাইকারি', function () {
    $retailOnly = Product::factory()->retailOnly()->create();

    Livewire::test(CreateOrder::class)
        ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
        ->set('data.lines', ['a' => ['product_id' => $retailOnly->getKey(), 'kilograms' => 1]])
        ->call('create')
        ->assertHasFormErrors(['lines.a.product_id']);

    expect(Order::query()->exists())->toBeFalse();
});
