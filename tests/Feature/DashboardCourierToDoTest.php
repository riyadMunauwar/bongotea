<?php

use App\Enums\OrderStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Widgets\CourierMoneyTable;
use App\Filament\Widgets\DeliveryMixTable;
use App\Filament\Widgets\NeedsAttentionTable;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\UnsentOrdersStat;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Couriers\CourierSweep;
use App\Support\Inventory\DaysOfCover;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The to-do list on the Dashboard: what demands somebody act, and nothing
 * that merely reports.
 *
 * Three widgets carry it — the backlog of Orders nobody has sent, Courier
 * money, and Needs Attention — and all three ignore the Reporting Window
 * drawn above them, which is the whole distinction between this screen and
 * the Courier Report. The windowed analytics are `CourierReportScreenTest`'s,
 * and what the money says is `CourierMoneyTest`'s.
 *
 * What is asserted here is what a User meets: a backlog figure that is one
 * click from the list it counted, and the conditions reaching the screen in
 * the words somebody acts on — the condition, what to do about it, and the
 * record it opens. The three parcel ones open an Order; the shelf running out
 * opens a Product, which is the one row here that is not about an Order at
 * all. When each condition *begins* is `ReportingNeedsAttentionTest`'s, and
 * what Days of Cover actually says is `InventoryDaysOfCoverTest`'s; neither
 * is measured again here.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The backlog tile as the widget builds it rather than as HTML: a figure
 * read out of a rendered cell is a figure a test can misread.
 */
function backlogTile(): Stat
{
    return (fn (): array => $this->getStats())->call(Livewire::test(UnsentOrdersStat::class)->instance())[0];
}

/**
 * The Orders queue as the tile's own link opens it — the address parsed back
 * into the query string a browser would send, so what is asserted is the
 * list a User lands on rather than a filter the test applied itself.
 */
function queueBehindTheBacklogTile(): Testable
{
    parse_str((string) parse_url((string) backlogTile()->getUrl(), PHP_URL_QUERY), $parameters);

    return Livewire::withQueryParams($parameters)->test(ListOrders::class);
}

/*
|--------------------------------------------------------------------------
| Orders nobody has sent
|--------------------------------------------------------------------------
*/

it('counts the Orders nobody has sent to a Courier, and opens on exactly those', function () {
    $new = Order::factory()->create();
    $confirmed = Order::factory()->confirmed()->create();
    $cancelled = Order::factory()->cancelled()->create();

    /** Out with a Courier, so somebody has already acted on it. */
    $shipping = Order::factory()->create();
    Shipment::factory()->for($shipping)->create();

    /** At a door, which is the other way an Order leaves the backlog for good. */
    $arrived = Order::factory()->create();
    Shipment::factory()->for($arrived)->delivered()->create();

    expect(backlogTile()->getValue())->toBe('3');

    /**
     * The count and the list are one question asked twice, so the link
     * carries the tab as well as the filter: `ListOrders` opens on New, and
     * an address naming only the filter would hide the Confirmed and the
     * Cancelled Order the tile had just counted.
     */
    queueBehindTheBacklogTile()
        ->assertCanSeeTableRecords([$new, $confirmed, $cancelled])
        ->assertCanNotSeeTableRecords([$shipping, $arrived]);
});

it('drops an Order from the count the moment a parcel goes out for it', function () {
    $order = Order::factory()->create();

    expect(backlogTile()->getValue())->toBe('1')
        ->and(backlogTile()->getUrl())->toBe(OrderResource::notYetSentUrl());

    Shipment::factory()->for($order)->create();

    /**
     * A nought says the backlog is worked through, and keeps its link: the
     * one way to tell that apart from a tile that has stopped counting is to
     * open the queue and be shown the empty list under the filter that
     * produced it.
     */
    expect(backlogTile()->getValue())->toBe('0')
        ->and(backlogTile()->getUrl())->toBe(OrderResource::notYetSentUrl())
        ->and(backlogTile()->getDescription())->toContain('Every Order is out with a Courier');

    queueBehindTheBacklogTile()->assertCanNotSeeTableRecords([$order]);
});

it('ignores the Reporting Window: an Order captured months ago is still waiting for a Courier', function () {
    $forgotten = Order::factory()->create(['created_at' => now()->subMonths(6)]);

    /** Narrowed to a single day, which the rest of the screen obeys and this does not. */
    Livewire::test(Dashboard::class)->set('filters.'.ReportingWindow::PRESET_FILTER, 'today');

    expect(backlogTile()->getValue())->toBe('1');

    queueBehindTheBacklogTile()->assertCanSeeTableRecords([$forgotten]);
});

it('draws the backlog on the Dashboard under a subheading that disowns the Reporting Window, without moving a widget already there', function () {
    Order::factory()->create();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(UnsentOrdersStat::class);

    Livewire::test(UnsentOrdersStat::class)
        ->assertSee('Waiting for a Courier')
        ->assertSee('Not yet sent to a Courier')
        ->assertSee('whatever the Reporting Window above says')
        ->assertSee('Book them from the Orders list');

    /**
     * Between the last windowed report and Courier money, so the three
     * widgets that ignore the window are read as one group at the foot of
     * the screen and nothing already on it moves.
     */
    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(DeliveryMixTable::class, $widgets, true))
        ->toBeLessThan(array_search(UnsentOrdersStat::class, $widgets, true))
        ->and(array_search(UnsentOrdersStat::class, $widgets, true))
        ->toBeLessThan(array_search(CourierMoneyTable::class, $widgets, true))
        ->and(array_search(CourierMoneyTable::class, $widgets, true))
        ->toBeLessThan(array_search(NeedsAttentionTable::class, $widgets, true))
        ->and(app(UnsentOrdersStat::class)->getColumnSpan())->toBe('full');
});

/*
|--------------------------------------------------------------------------
| Parcels that went wrong
|--------------------------------------------------------------------------
*/

it('puts a parcel that came back in front of the User, and takes it away on either decision', function () {
    $resent = Order::factory()->confirmed()->create();
    Shipment::factory()->for($resent)->returned()->create();

    $dropped = Order::factory()->confirmed()->create();
    Shipment::factory()->for($dropped)->returned()->create();

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee($resent->order_number)
        ->assertSee($dropped->order_number)
        ->assertSee('Parcel came back')
        ->assertSee('Send it again or cancel the Order')
        /** The row is the way in, as every Needs Attention row is: acting on it is opening the Order. */
        ->assertSee(OrderResource::getUrl('view', ['record' => $resent]));

    /** The two decisions the row names, one each: a parcel out again, and an Order nobody is sending anything for. */
    Shipment::factory()->for($resent)->create();
    $dropped->update(['status' => OrderStatus::Cancelled]);

    Livewire::test(NeedsAttentionTable::class)
        ->assertDontSee($resent->order_number)
        ->assertDontSee($dropped->order_number)
        ->assertSee('Nothing needs attention');
});

it('asks the User which items came back from a parcel that half arrived, and stops asking once they say', function () {
    $halfArrived = Order::factory()->confirmed()->create();
    Shipment::factory()->for($halfArrived)->partlyDelivered()->create();

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee($halfArrived->order_number)
        ->assertSee('Parcel half arrived')
        ->assertSee('Say which items came back')
        /** The row is the way in: acting on it is opening the Order, where the question is asked. */
        ->assertSee(OrderResource::getUrl('view', ['record' => $halfArrived]));

    /**
     * The one thing that settles it. Not cancelling and not sending another
     * parcel — neither says which items are sitting in a courier's warehouse
     * (ADR-0021).
     */
    $halfArrived->shipments()->sole()->forceFill(['answered_at' => now()])->save();

    Livewire::test(NeedsAttentionTable::class)
        ->assertDontSee($halfArrived->order_number)
        ->assertSee('Nothing needs attention');
});

/*
|--------------------------------------------------------------------------
| Shelves running out
|--------------------------------------------------------------------------
*/

it('puts a shelf that will not last in front of the User with the days left on it, and opens the Product it is about', function () {
    $running = shelfOf('Sylhet tea', 30);
    parcelShippedOn($running, 28, reportingNow()->subDays(10));

    $comfortable = shelfOf('Winter jacket', 280);
    parcelShippedOn($comfortable, 28, reportingNow()->subDays(10));

    $this->travelTo(reportingNow());

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee('Sylhet tea')
        ->assertSee('Stock running out')

        /** The figure, because five Products all running out is not a list anybody can work in order. */
        ->assertSee('2.0 days of cover')
        ->assertSee('Buy more before it runs out')

        /** The row is the way in, as every row here is — onto the movements that are emptying the shelf. */
        ->assertSee(ProductResource::getUrl('stock', ['record' => $running]))

        /** Two hundred and fifty-two days of cover is nobody's to act on. */
        ->assertDontSee('Winter jacket');
});

it('ignores the Reporting Window: the widget is handed one narrowed to a day nothing went out on and draws the same shelf and the same figure', function () {
    $running = shelfOf('Sylhet tea', 30);
    parcelShippedOn($running, 28, reportingNow()->subDays(10));

    $this->travelTo(reportingNow());

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee('Sylhet tea')
        ->assertSee('2.0 days of cover');

    /**
     * The window a windowed widget would obey, handed to this one: narrowed
     * to today, over which not one unit of this went out, and over which the
     * whole catalogue would therefore have no velocity at all. The list and
     * the figure are unmoved, because the rate behind cover is read over a
     * trailing span of its own — a to-do list that shrank when a Merchant
     * moved a control about something else would be a to-do list nobody could
     * trust to be empty.
     *
     * Passed as the mount argument rather than set on a throwaway Dashboard,
     * because a filter set on one component never reaches another: a test
     * that only set it would pass whatever this widget did with a window.
     */
    Livewire::test(NeedsAttentionTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSee('Sylhet tea')
        ->assertSee('2.0 days of cover');

    /**
     * And it cannot be wired to one by accident. A widget reads the Dashboard
     * filter by declaring `pageFilters` through `InteractsWithPageFilters`;
     * this one declares neither, which is what makes the mount argument above
     * something Livewire quietly ignores rather than something obeyed.
     */
    expect(property_exists(NeedsAttentionTable::class, 'pageFilters'))->toBeFalse()
        ->and(in_array(InteractsWithPageFilters::class, class_uses_recursive(NeedsAttentionTable::class), true))->toBeFalse()

        /** `OverviewStats` is the control: a widget that does obey the window has both. */
        ->and(in_array(InteractsWithPageFilters::class, class_uses_recursive(OverviewStats::class), true))->toBeTrue();
});

it('offers no way to count cover over a window, so nothing can narrow it from a screen', function () {
    /**
     * The absence `StockReportScreenTest` pins for its page, pinned here for
     * the figure: neither the to-do list nor the cover module takes a
     * `ReportingWindow`, so there is no parameter for a later hand to thread a
     * screen's filter into (ADR-0018).
     */
    $windowed = fn (string $class, string $method): bool => collect((new ReflectionMethod($class, $method))->getParameters())
        ->contains(fn (ReflectionParameter $parameter): bool => (string) $parameter->getType() === ReportingWindow::class);

    expect($windowed(ReportingQuery::class, 'needsAttention'))->toBeFalse()
        ->and($windowed(DaysOfCover::class, 'runningOut'))->toBeFalse()
        ->and($windowed(DaysOfCover::class, 'of'))->toBeFalse()

        /** The control again: a windowed result says so in its own signature. */
        ->and($windowed(ReportingQuery::class, 'overview'))->toBeTrue();
});

it('puts a parcel nobody has heard about in front of the User once the sweep has flagged it, and not before', function () {
    $this->travelTo(reportingNow());

    $silent = Order::factory()->confirmed()->create();
    Shipment::factory()->for($silent)->create(['booked_at' => reportingNow()->subSeconds(CourierSweep::STUCK_AFTER_SECONDS + 60)->utc()]);

    $fresh = Order::factory()->confirmed()->create();
    Shipment::factory()->for($fresh)->create(['booked_at' => reportingNow()->subSeconds(CourierSweep::STUCK_AFTER_SECONDS - 60)->utc()]);

    /** The horizon is the sweep's own and is never measured a second time on the way to the screen. */
    Livewire::test(NeedsAttentionTable::class)->assertSee('Nothing needs attention');

    app(CourierSweep::class)->run();

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee($silent->order_number)
        ->assertSee('Nothing heard for a week')
        ->assertSee('Chase the Courier')
        ->assertSee(OrderResource::getUrl('view', ['record' => $silent]))
        ->assertDontSee($fresh->order_number);
});
