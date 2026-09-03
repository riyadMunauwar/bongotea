<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * An Order as Capture leaves it: one line, snapshotted from a Product.
 */
function capturedOrder(int $price = 99000, int $quantity = 1, array $attributes = []): Order
{
    $order = Order::factory()->create(array_merge([
        'customer_name' => 'Rahim Uddin',
        'customer_district' => 'Dhaka',
        'delivery_charge' => 6000,
    ], $attributes));

    OrderLine::factory()
        ->for($order)
        ->for(Product::factory()->create(['name' => 'Winter Jacket', 'price' => $price]))
        ->create(['product_name' => 'Winter Jacket', 'unit_price' => $price, 'quantity' => $quantity]);

    return $order->refresh();
}

/**
 * Edit an Order's lines through the real form, keeping each repeater item's
 * identity. Filling the repeater with a bare list would ask Filament to replace
 * the lines rather than update them, which is not what an operator correcting a
 * quantity does.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $change
 */
function editingLines(Order $order, callable $change): void
{
    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);

    $lines = [];

    foreach ($component->get('data.lines') as $key => $line) {
        $lines[$key] = $change($line);
    }

    $component->fillForm(['lines' => $lines])
        ->call('save')
        ->assertHasNoFormErrors();
}

it('opens the fulfilment screens', function (string $page) {
    $order = capturedOrder();

    $this->get(str_replace('{record}', (string) $order->getKey(), $page))->assertOk();
})->with([
    'the queue' => '/admin/orders',
    'one order' => '/admin/orders/{record}',
    'working an order' => '/admin/orders/{record}/edit',
]);

it('offers no way to type an order in by hand', function () {
    /**
     * An Order exists because a visitor placed one. One typed here would have
     * no Page behind it and no Customer resolved from a phone number.
     */
    $this->get('/admin/orders/create')->assertNotFound();
});

it('shows an order a human can act on', function () {
    $order = capturedOrder();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSeeText($order->order_number)
        ->assertSeeText('Rahim Uddin')
        ->assertSeeText($order->customer_phone);
});

it('narrows the queue to the orders taken in a date range', function () {
    $today = capturedOrder();
    $lastWeek = capturedOrder(attributes: ['created_at' => now()->subWeek()]);

    Livewire::test(ListOrders::class)
        ->filterTable('taken', ['from' => now()->toDateString()])
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$lastWeek]);
});

it('records the outcome of the confirmation call', function () {
    $order = capturedOrder();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['status' => OrderStatus::Confirmed->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
});

it('recomputes the totals from the captured price when a quantity is corrected', function () {
    $order = capturedOrder(price: 99000);

    /**
     * The product is repriced between Capture and the call. Editing the order
     * must not notice.
     */
    $order->lines()->sole()->product->update(['price' => 129000]);

    editingLines($order, fn (array $line): array => [...$line, 'quantity' => 3]);

    $order->refresh();

    expect($order->lines()->sole()->unit_price)->toBe(99000)
        ->and($order->lines()->sole()->line_total)->toBe(297000)
        ->and($order->subtotal)->toBe(297000)
        ->and($order->total)->toBe(303000);
});

it('keeps the total including delivery when the delivery charge is edited', function () {
    $order = capturedOrder(price: 99000);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['delivery_charge' => '120'])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    /** The figure the courier collects at the door always includes delivery. */
    expect($order->delivery_charge)->toBe(12000)
        ->and($order->subtotal)->toBe(99000)
        ->and($order->total)->toBe(111000);
});

it('refuses to let the captured price be edited away', function () {
    $order = capturedOrder(price: 99000);

    editingLines($order, fn (array $line): array => [
        ...$line,
        'unit_price' => '1',
        'product_name' => 'Something else',
    ]);

    /**
     * Filament hands you full-field editing by default, so what was agreed at
     * Capture has to be actively held shut rather than assumed immutable.
     */
    $line = $order->refresh()->lines()->sole();

    expect($line->unit_price)->toBe(99000)
        ->and($line->product_name)->toBe('Winter Jacket')
        ->and($order->total)->toBe(105000);
});

it('keeps a trail of what changed after capture', function () {
    $order = capturedOrder();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_address' => 'House 9, Road 3, Dhanmondi'])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * An address that changed between Capture and dispatch is exactly what you
     * want a trail for when a parcel goes missing.
     */
    expect($order->activitiesAsSubject()->count())->toBeGreaterThan(0);
});

/**
 * The figures are the reason to open the editor, so they have to move while it
 * is open. These assert the screen, not the database: what is stored after a
 * save is already covered above.
 */
it('shows what a corrected quantity will cost before anything is saved', function () {
    $order = capturedOrder(price: 99000);

    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);

    $lines = [];

    foreach ($component->get('data.lines') as $key => $line) {
        $lines[$key] = [...$line, 'quantity' => 3];
    }

    $component->fillForm(['lines' => $lines])
        ->assertSeeText(Money::format(297000))
        ->assertSeeText(Money::format(303000));

    /** Nothing was saved: those figures came from form state, not the Order. */
    expect($order->refresh()->subtotal)->toBe(99000)
        ->and($order->total)->toBe(105000);
});

it('shows what a corrected delivery charge will cost before anything is saved', function () {
    $order = capturedOrder(price: 99000);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['delivery_charge' => '120'])
        ->assertSeeText(Money::format(111000));

    expect($order->refresh()->total)->toBe(105000);
});

/**
 * The document is deliberately shaped to look routine, so a status badge alone
 * is easy to skim past. The cost of missing one is a courier delivering goods
 * nobody is going to pay for.
 */
it('warns on both screens that a cancelled order must not be sent', function (string $page) {
    $order = capturedOrder(attributes: ['status' => OrderStatus::Cancelled]);

    Livewire::test($page, ['record' => $order->getKey()])
        ->assertSeeText('Cancelled — this order will not be fulfilled.');
})->with([
    'reading it' => ViewOrder::class,
    'working it' => EditOrder::class,
]);

it('warns that a deleted order has been removed', function () {
    $order = capturedOrder();
    $order->delete();

    /** Reachable at all because OrderResource drops the soft-deleting scope. */
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText('Deleted — this order has been removed.');
});

it('leaves an order that is fine unmarked', function () {
    $order = capturedOrder();

    /**
     * The whole sentence, not a fragment of it: the status hint explains what
     * cancelling means on every order, so a substring would match the healthy
     * document too and this test would never fail.
     */
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertDontSeeText('Cancelled — this order will not be fulfilled.')
        ->assertDontSeeText('Deleted — this order has been removed.');
});

it('refuses to let the customer phone be edited away', function () {
    $order = capturedOrder();

    $phone = $order->customer_phone;

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_phone' => '01911111111'])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * A customer *is* their phone number. Rendering it as text rather than as a
     * disabled input must not have quietly reopened it for writing.
     */
    expect($order->refresh()->customer_phone)->toBe($phone);
});
