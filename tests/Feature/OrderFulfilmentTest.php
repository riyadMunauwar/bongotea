<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
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

/**
 * A Customer *is* their phone number, so correcting the number on an Order is
 * not correcting an attribute — it is saying the Order belongs to someone else.
 * These pin the link, not just the snapshot.
 */
it('moves the order to the customer who owns a corrected number', function () {
    $order = capturedOrder();

    $originalCustomer = $order->customer_id;

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_phone' => '01911111111'])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->customer_phone)->toBe('+8801911111111')
        ->and($order->customer_id)->not->toBe($originalCustomer)
        ->and($order->customer->phone)->toBe('+8801911111111');
});

it('reuses the existing customer when the corrected number is already known', function () {
    $order = capturedOrder();

    $existing = Customer::forPhone('01911111111');

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_phone' => '01911111111'])
        ->call('save')
        ->assertHasNoFormErrors();

    /** Re-pointed at the identity that already existed, not a second copy of it. */
    expect($order->refresh()->customer_id)->toBe($existing->getKey())
        ->and(Customer::where('phone', '+8801911111111')->count())->toBe(1);
});

it('stores a corrected number in its canonical form', function () {
    $order = capturedOrder();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_phone' => '01911-111111'])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Punctuation is not information. Storing it as typed does not look like a
     * bug — it looks like two customers.
     */
    expect($order->refresh()->customer_phone)->toBe('+8801911111111')
        ->and(Customer::where('phone', '+8801911111111')->count())->toBe(1);
});

it('saves an order whose untouched number no longer fits the phone rules the merchant has since changed', function () {
    $order = capturedOrder();

    $originalCustomer = $order->customer_id;
    $originalPhone = $order->customer_phone;

    /** Captured as a Bangladeshi number; the installation has since moved to the United Kingdom. */
    $general = app(GeneralSettings::class);
    $general->phoneCountryCode = '44';
    $general->phoneTrunkPrefix = '0';
    $general->phoneNationalLengthMin = 10;
    $general->phoneNationalLengthMax = 10;
    $general->save();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_name' => 'Rahim Uddin Ahmed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->customer_name)->toBe('Rahim Uddin Ahmed')
        ->and($order->customer_phone)->toBe($originalPhone)
        ->and($order->customer_id)->toBe($originalCustomer);
});

it('refuses a corrected number no courier could call', function () {
    $order = capturedOrder();

    $phone = $order->customer_phone;

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_phone' => 'not a number'])
        ->call('save')
        ->assertHasFormErrors(['customer_phone']);

    expect($order->refresh()->customer_phone)->toBe($phone);
});

/**
 * The two corrections a confirmation call actually produces, made together.
 *
 * Filament saves the repeater's lines before it saves the Order, and the line
 * observer recalculates through its own `belongsTo` — a different instance from
 * the one being edited. The Order must not then write a total derived from the
 * subtotal it was still holding.
 */
it('keeps the total matching its parts when a quantity and the delivery charge are corrected together', function () {
    $order = capturedOrder(price: 99000);

    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);

    $lines = [];

    foreach ($component->get('data.lines') as $key => $line) {
        $lines[$key] = [...$line, 'quantity' => 3];
    }

    $component->fillForm(['lines' => $lines, 'delivery_charge' => '120'])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    /** What the courier collects at the door, and what the screen promised. */
    expect($order->subtotal)->toBe(297000)
        ->and($order->delivery_charge)->toBe(12000)
        ->and($order->total)->toBe(309000);
});

/**
 * OrderResource drops the soft-deleting scope from its route binding and the
 * document tells you to restore the order before working it, so this screen is
 * somewhere an operator lands and can type into.
 */
it('works an order that has been deleted without falling over', function () {
    $order = capturedOrder(price: 99000);
    $order->delete();

    editingLines($order, fn (array $line): array => [...$line, 'quantity' => 2]);

    $order->refresh();

    expect($order->subtotal)->toBe(198000)
        ->and($order->total)->toBe(204000);
});

it('works an order taken on a form that never asked for an address', function () {
    /**
     * A Page whose Checkout asks for a phone number and nothing else — a
     * lead-capture campaign, or one where the courier calls to arrange
     * everything. The Order is complete as far as it goes, so it queues, opens
     * and saves like any other.
     */
    $order = capturedOrder(attributes: [
        'customer_name' => null,
        'customer_address' => null,
        'customer_district' => null,
    ]);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSeeText($order->customer_phone);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText('Not asked for');

    /**
     * Nothing here may demand a detail the Checkout never collected: an
     * operator recording a confirmation call would otherwise have to invent an
     * address before the screen would save.
     */
    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['status' => OrderStatus::Confirmed->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->customer_address)->toBeNull()
        ->and($order->customer_name)->toBeNull();
});

it('stores nothing rather than a blank when a detail is cleared by hand', function () {
    $order = capturedOrder(attributes: ['customer_address' => 'House 4, Road 12, Banani']);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['customer_address' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    /** The same absence Capture writes, so the column holds two states and not three. */
    expect($order->refresh()->customer_address)->toBeNull();
});

it('shows an email address only where one was captured', function () {
    $withEmail = capturedOrder(attributes: ['customer_email' => 'rahim@example.com']);

    Livewire::test(ViewOrder::class, ['record' => $withEmail->getKey()])
        ->assertSeeText('rahim@example.com');

    $withoutEmail = capturedOrder();

    Livewire::test(ViewOrder::class, ['record' => $withoutEmail->getKey()])
        ->assertSeeText('Not asked for');
});

it('reads the questions a merchant added of their own off the order alone', function (string $screen) {
    $order = capturedOrder(attributes: ['answers' => [
        ['key' => 'colour', 'label' => 'Which colour?', 'type' => 'select', 'value' => 'moss', 'option_label' => 'Moss green'],
        ['key' => 'gift_wrap', 'label' => 'Gift wrap this', 'type' => 'checkbox', 'value' => true, 'option_label' => null],
        ['key' => 'gift_note', 'label' => 'Gift note', 'type' => 'text', 'value' => null, 'option_label' => null],
    ]]);

    /**
     * The page it was captured on is gone altogether, which is the strongest
     * form of the question this screen must never ask: everything it needs was
     * copied onto the order at Capture (ADR-0006).
     */
    $order->page->products()->detach();
    $order->page->forceDelete();

    Livewire::test($screen, ['record' => $order->getKey()])
        ->assertSee('Which colour?')
        /** The wording the customer picked it under, not the value submitted. */
        ->assertSee('Moss green')
        ->assertSee('Gift wrap this')
        ->assertSee('Yes')
        /** Asked and left alone, which is a different thing from never asked. */
        ->assertSee('Gift note')
        ->assertSee('Left blank');
})->with(['view' => ViewOrder::class, 'edit' => EditOrder::class]);

it('says nothing about questions on an order whose page asked none of its own', function (string $screen) {
    $order = capturedOrder();

    /**
     * Most orders. An empty heading over nothing is a section a fulfiller
     * learns to skip, and the ones that matter go with it.
     */
    Livewire::test($screen, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertDontSee('Also asked');
})->with(['view' => ViewOrder::class, 'edit' => EditOrder::class]);

it('leaves the answers a merchant question collected alone when an order is corrected', function () {
    $order = capturedOrder(attributes: ['answers' => [
        ['key' => 'colour', 'label' => 'Which colour?', 'type' => 'select', 'value' => 'moss', 'option_label' => 'Moss green'],
    ]]);

    /**
     * What was agreed at Capture is what gets collected at the door, and these
     * answers are agreed the way a product's name and price are. Correcting a
     * quantity must not disturb them.
     */
    editingLines($order, fn (array $line): array => [...$line, 'quantity' => 3]);

    expect($order->refresh()->answers[0]->toArray())
        ->toBe(['key' => 'colour', 'label' => 'Which colour?', 'type' => 'select', 'value' => 'moss', 'option_label' => 'Moss green']);
});
