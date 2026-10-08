<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
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
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->workingOrders()->create());
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

it('answers a record address that is not an id without asking the database, because PostgreSQL raises on it', function () {
    expect(Route::getRoutes()->getByName('filament.admin.resources.orders.view')->wheres)
        ->toBe(['record' => '[0-9]+']);
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

it('shows capture times and cuts the date filter at the merchant timezone midnight, not UTC', function () {
    /**
     * Dhaka is six hours ahead of UTC, so these two Orders are a minute
     * apart across a Dhaka midnight and a minute apart inside a UTC evening.
     */
    $lateOnTheEighth = capturedOrder(attributes: ['created_at' => '2026-09-08 17:30:00']);
    $earlyOnTheNinth = capturedOrder(attributes: ['created_at' => '2026-09-08 18:30:00']);

    Livewire::test(ListOrders::class)
        ->assertSee('Sep 8, 2026 23:30:00')
        ->assertSee('Sep 9, 2026 00:30:00')
        ->filterTable('taken', ['from' => '2026-09-08', 'until' => '2026-09-08'])
        ->assertCanSeeTableRecords([$lateOnTheEighth])
        ->assertCanNotSeeTableRecords([$earlyOnTheNinth]);

    $general = app(GeneralSettings::class);
    $general->timezone = 'UTC';
    $general->save();

    Livewire::test(ListOrders::class)
        ->assertSee('Sep 8, 2026 17:30:00')
        ->filterTable('taken', ['from' => '2026-09-08', 'until' => '2026-09-08'])
        ->assertCanSeeTableRecords([$lateOnTheEighth, $earlyOnTheNinth]);

    /** The stored instants did not move. */
    expect($lateOnTheEighth->refresh()->created_at->toIso8601String())->toBe('2026-09-08T17:30:00+00:00');
});

/*
|--------------------------------------------------------------------------
| The queue's tabs
|--------------------------------------------------------------------------
*/

it('opens on the orders waiting for a call, and gives every status a tab of its own', function () {
    $new = capturedOrder();
    $confirmed = capturedOrder(attributes: ['status' => OrderStatus::Confirmed]);
    $cancelled = capturedOrder(attributes: ['status' => OrderStatus::Cancelled]);

    /**
     * Cold, with no tab named in the URL: this screen is the confirmation-call
     * queue, so it opens on the calls nobody has made yet rather than on the
     * archive. The tabs are the only control for the fulfilment axis — the
     * status filter was removed so that two controls cannot disagree.
     */
    Livewire::test(ListOrders::class)
        ->assertSet('activeTab', 'new')
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$confirmed, $cancelled])
        ->set('activeTab', 'confirmed')
        ->assertCanSeeTableRecords([$confirmed])
        ->assertCanNotSeeTableRecords([$new, $cancelled])
        ->set('activeTab', 'cancelled')
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$new, $confirmed])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$new, $confirmed, $cancelled]);
});

it('counts the orders waiting on a call once, for the sidebar and the tab alike', function () {
    capturedOrder();
    capturedOrder();
    capturedOrder(attributes: ['status' => OrderStatus::Confirmed]);

    $newTab = fn (): Tab => Livewire::test(ListOrders::class)->instance()->getCachedTabs()['new'];

    expect(OrderResource::getNavigationBadge())->toBe('2')
        ->and($newTab()->getBadge())->toBe('2')
        ->and($newTab()->getBadgeColor())->toBe('warning');

    Order::query()->update(['status' => OrderStatus::Confirmed]);

    /** Nothing waiting is the good state, and it wears no badge at either end. */
    expect(OrderResource::getNavigationBadge())->toBeNull()
        ->and($newTab()->getBadge())->toBeNull();
});

it('names the tab that swallowed a search, and offers the whole list instead', function () {
    $confirmed = capturedOrder(attributes: [
        'status' => OrderStatus::Confirmed,
        'customer_phone' => '01712345678',
    ]);

    /** An empty queue with nothing set is the good state, not a dead end. */
    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('showInAllOrders');

    /**
     * The customer rings back about an order somebody confirmed yesterday. An
     * empty table with no reason given reads as "no such order", which is the
     * one thing this screen must never say about an order it holds.
     */
    Livewire::test(ListOrders::class)
        ->searchTable('01712345678')
        ->assertCanNotSeeTableRecords([$confirmed])
        ->assertSeeText('No New orders match 01712345678')
        ->callTableAction('showInAllOrders')
        ->assertSet('activeTab', 'all')
        ->assertSet('tableSearch', '01712345678')
        ->assertCanSeeTableRecords([$confirmed]);
});

it('does not blame the tab for a date range nothing falls in', function () {
    capturedOrder(attributes: ['created_at' => now()->subWeek()]);

    /**
     * The date range and the trashed filter say nothing about status, so an
     * empty table under one of them is not the tab's doing. Offering All here
     * would carry the same filter across and land the operator on Filament's
     * silent empty state — less to go on than before the click.
     */
    Livewire::test(ListOrders::class)
        ->filterTable('taken', ['from' => now()->toDateString()])
        ->assertTableActionHidden('showInAllOrders')
        ->assertDontSeeText('may have another status');
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

it('works an order that holds no name or address', function () {
    /**
     * A phone order taken with a number and a District and nothing else, where
     * the courier calls to arrange the rest. The Order is complete as far as
     * it goes, so it queues, opens and saves like any other.
     */
    $order = capturedOrder(attributes: [
        'customer_name' => null,
        'customer_address' => null,
        'customer_district' => 'Dhaka',
    ]);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertSeeText($order->customer_phone);

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSeeText('Not asked for');

    /**
     * Nothing here may demand an optional detail the Order never held: an
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
