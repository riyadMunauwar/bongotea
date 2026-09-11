<?php

use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Widgets\CachePathIndicator;
use App\Filament\Widgets\DeliveryMixTable;
use App\Filament\Widgets\NeedsAttentionTable;
use App\Filament\Widgets\OrderStatusBreakdownChart;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PageLeaderboardTable;
use App\Filament\Widgets\PaymentMethodMixChart;
use App\Filament\Widgets\SalesOverTimeChart;
use App\Filament\Widgets\TopProductsTable;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/*
| The counting is proved against the query module in ReportingOverviewTest;
| what is asserted here is what a User meets on the Dashboard — the
| Reporting Window filter and what it remembers, the tile row and one figure
| drawn through it with the currency symbol, and that the Cache Path
| indicator has kept its place below.
*/

it('carries the Reporting Window filter above the tile row, defaulting to the last seven days', function () {
    Page::factory()->published()->create();

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Reporting Window')
        ->assertSeeLivewire(OverviewStats::class)
        ->assertSeeLivewire(CachePathIndicator::class);

    Livewire::test(Dashboard::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'last_7_days'], 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::UNTIL_FILTER, 'filtersForm');
});

it('offers two date pickers once Custom is chosen, and remembers the choice for the session', function () {
    /**
     * Set as the browser sets a live field, one property update at a time,
     * because that is what Filament persists on; `fillForm()` writes the
     * state through a method call the persistence hook never sees.
     */
    Livewire::test(Dashboard::class)
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'custom')
        ->assertSchemaComponentVisible(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentVisible(ReportingWindow::UNTIL_FILTER, 'filtersForm')
        ->set('filters.'.ReportingWindow::FROM_FILTER, '2026-09-01')
        ->set('filters.'.ReportingWindow::UNTIL_FILTER, '2026-09-03');

    /** A fresh visit reads the session rather than the default. */
    Livewire::test(Dashboard::class)
        ->assertSchemaStateSet([
            ReportingWindow::PRESET_FILTER => 'custom',
            ReportingWindow::FROM_FILTER => '2026-09-01',
            ReportingWindow::UNTIL_FILTER => '2026-09-03',
        ], 'filtersForm');
});

it('draws the tiles for the chosen window, money with the currency symbol, and changes when the filter does', function () {
    Order::factory()->create(['subtotal' => 50000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->create(['subtotal' => 20000, 'delivery_charge' => 6000, 'created_at' => now()->subDays(3)]);

    $symbol = app(GeneralSettings::class)->currencySymbol;

    Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Orders')
        ->assertSee('Sales')
        ->assertSee('Average Order Value')
        ->assertSee('Cancellation Rate')
        ->assertSee('Unpaid')
        ->assertSee('New awaiting call')
        ->assertSee('Visits')
        ->assertSee('Not counted')
        ->assertSee($symbol.'820.00')
        ->assertSee($symbol.'410.00');

    Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSee($symbol.'560.00')
        ->assertDontSee($symbol.'820.00');

    /**
     * The widget is lazy, so the Dashboard's own render carries only its
     * placeholder; what is pinned instead is the hand-over — the page's
     * filter, once changed, is what every widget is mounted with.
     */
    $dashboard = Livewire::test(Dashboard::class)
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'today')
        ->instance();

    $widget = collect($dashboard->getWidgetsSchemaComponents($dashboard->getWidgets()))
        ->first(fn ($component): bool => $component->getComponent() === OverviewStats::class);

    expect($widget->getData()['pageFilters'][ReportingWindow::PRESET_FILTER])->toBe('today');
});

it('measures each tile against the comparison window, up or down', function () {
    Order::factory()->create(['subtotal' => 50000, 'created_at' => now()]);
    Order::factory()->cancelled()->create(['subtotal' => 50000, 'created_at' => now()]);
    Order::factory()->cancelled()->create(['subtotal' => 50000, 'created_at' => now()->subDays(8)]);

    /**
     * Two Orders against one: up. Sales against a window that sold nothing:
     * up from none. Half cancelled against all cancelled: down, in points.
     */
    Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('100% up on the previous 7 days')
        ->assertSee('Up from none')
        ->assertSee('50.0%')
        ->assertSee('50.0 pts down on the previous 7 days');
});

it('draws Sales over time below the tiles: a Sales line and an Orders line over every bucket of the chosen window', function () {
    Page::factory()->published()->create();
    Order::factory()->create(['subtotal' => 50000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->cancelled()->create(['subtotal' => 20000, 'delivery_charge' => 6000, 'created_at' => now()]);

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(SalesOverTimeChart::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(OverviewStats::class, $widgets, true))
        ->toBeLessThan(array_search(SalesOverTimeChart::class, $widgets, true))
        ->and(array_search(SalesOverTimeChart::class, $widgets, true))
        ->toBeLessThan(array_search(CachePathIndicator::class, $widgets, true));

    /**
     * The data is handed to Chart.js as JavaScript, so it is read off the
     * widget rather than out of the HTML: seven daily points, the last of
     * them today, Sales in major units and Orders counting the Cancelled one.
     * Hourly bucketing is proved against the module in ReportingSalesOverTimeTest.
     */
    $chart = Livewire::test(SalesOverTimeChart::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Sales over time')
        ->instance();

    $data = (fn (): array => $this->getData())->call($chart);

    expect($data['labels'])->toHaveCount(7)
        ->and($data['datasets'][0]['label'])->toBe('Sales')
        ->and($data['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 560.0])
        ->and($data['datasets'][1]['label'])->toBe('Orders')
        ->and($data['datasets'][1]['data'])->toBe([0, 0, 0, 0, 0, 0, 2]);
});

it('draws the Page leaderboard last of the reports, above the Cache Path indicator: a row per Page with its own figures, Sales first and sortable, each row opening its Page', function () {
    $top = Page::factory()->published()->create(['title' => 'Summer combo', 'published_at' => now()->subDays(10)]);
    $other = Page::factory()->create(['title' => 'Winter combo']);
    $retired = Page::factory()->published()->create(['title' => 'Retired combo']);

    Order::factory()->create(['page_id' => $top->id, 'subtotal' => 50000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->create(['page_id' => $other->id, 'subtotal' => 20000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->cancelled()->create(['page_id' => $other->id, 'subtotal' => 20000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->create(['page_id' => $retired->id, 'subtotal' => 10000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->create(['page_id' => null, 'page_slug' => 'gone-combo', 'subtotal' => 5000, 'delivery_charge' => 6000, 'created_at' => now()]);
    $retired->delete();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(PageLeaderboardTable::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(DeliveryMixTable::class, $widgets, true))
        ->toBeLessThan(array_search(PageLeaderboardTable::class, $widgets, true))
        ->and(array_search(PageLeaderboardTable::class, $widgets, true))
        ->toBeLessThan(array_search(CachePathIndicator::class, $widgets, true));

    $symbol = app(GeneralSettings::class)->currencySymbol;

    /**
     * Each Page's row carries that Page's figures alone: the top Page's
     * Sales, not the installation's; the other Page's Cancellation Rate,
     * not the top Page's. The retired Page keeps its row, marked, and the
     * hard-deleted one's Orders sit under their slug.
     */
    $leaderboard = Livewire::test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Page leaderboard')
        ->assertSee('Summer combo')
        ->assertSee($symbol.'560.00')
        ->assertDontSee($symbol.'1090.00')
        ->assertSee('Winter combo')
        ->assertSee('50.0%')
        ->assertDontSee('20.0%')
        ->assertSee('Retired combo')
        ->assertSeeHtml('">deleted</span>')
        ->assertSee('gone-combo')
        ->assertSee('Not counted')
        ->assertSee(PageResource::getUrl('edit', ['record' => $top]))
        ->assertSee(PageResource::getUrl('edit', ['record' => $retired]));

    $titles = fn (): array => $leaderboard->instance()->getTableRecords()->pluck('title')->values()->all();

    expect($titles())->toBe(['Summer combo', 'Winter combo', 'Retired combo', 'gone-combo']);

    $leaderboard->sortTable('orders', 'desc');

    expect($titles()[0])->toBe('Winter combo');

    Livewire::test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'yesterday']])
        ->assertDontSee('Summer combo')
        ->assertSee('No Orders in this window');
});

it('draws the status breakdown and the Payment Method mix side by side under the Sales chart, each obeying the filter', function () {
    Page::factory()->published()->create();
    Order::factory()->create(['subtotal' => 50000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Order::factory()->cancelled()->create(['subtotal' => 20000, 'delivery_charge' => 6000, 'created_at' => now()]);
    Payment::factory()->for(Order::factory()->create(['subtotal' => 10000, 'delivery_charge' => 6000, 'payment_method' => PaymentMethod::Bkash, 'created_at' => now()]))->paid()->create();
    Order::factory()->create(['subtotal' => 10000, 'delivery_charge' => 6000, 'payment_method' => PaymentMethod::Bkash, 'created_at' => now()->subDays(3)]);

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(OrderStatusBreakdownChart::class)
        ->assertSeeLivewire(PaymentMethodMixChart::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(SalesOverTimeChart::class, $widgets, true))
        ->toBeLessThan(array_search(OrderStatusBreakdownChart::class, $widgets, true))
        ->and(array_search(OrderStatusBreakdownChart::class, $widgets, true))
        ->toBeLessThan(array_search(PaymentMethodMixChart::class, $widgets, true))
        ->and(array_search(PaymentMethodMixChart::class, $widgets, true))
        ->toBeLessThan(array_search(CachePathIndicator::class, $widgets, true))
        ->and(app(OrderStatusBreakdownChart::class)->getColumnSpan())->toBe(1)
        ->and(app(PaymentMethodMixChart::class)->getColumnSpan())->toBe(1);

    /**
     * The data is handed to Chart.js as JavaScript, so it is read off each
     * widget rather than out of the HTML. The counting is proved against the
     * module in ReportingStatusBreakdownTest and ReportingPaymentMixTest;
     * pinned here is the hand-over, and that the filter changes the answer.
     */
    $chartData = fn (string $widget, string $preset): array => (fn (): array => $this->getData())->call(
        Livewire::test($widget, ['pageFilters' => [ReportingWindow::PRESET_FILTER => $preset]])->instance(),
    );

    $breakdown = $chartData(OrderStatusBreakdownChart::class, 'last_7_days');

    expect($breakdown['labels'])->toBe(['New', 'Confirmed', 'Cancelled'])
        ->and($breakdown['datasets'][0]['data'])->toBe([3, 0, 1]);

    expect($chartData(OrderStatusBreakdownChart::class, 'today')['datasets'][0]['data'])->toBe([2, 0, 1]);

    $mix = $chartData(PaymentMethodMixChart::class, 'last_7_days');
    $series = collect($mix['datasets'])->keyBy('label')->map(fn (array $dataset): array => $dataset['data']);

    expect($mix['labels'])->toBe(['Cash on Delivery', 'bKash'])
        ->and($series['Paid'])->toBe([0, 1])
        ->and($series['Unpaid'])->toBe([0, 1])
        ->and($series['Due on delivery'])->toBe([2, 0])
        ->and($series['Sales'])->toBe([560.0, 320.0]);

    expect($chartData(PaymentMethodMixChart::class, 'today')['datasets'][3]['data'])->toBe([560.0, 160.0]);

    Livewire::test(OrderStatusBreakdownChart::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Order status breakdown');

    Livewire::test(PaymentMethodMixChart::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Payment Method mix');
});

it('draws Needs Attention full width at the foot of the Dashboard above the leaderboard, saying in words that it ignores the window, each row naming its condition and opening its Order', function () {
    $uncalled = Order::factory()->create(['customer_name' => 'Rahim Uddin', 'created_at' => now()->subHours(25)]);
    $refundable = Order::factory()->cancelled()->create(['payment_method' => PaymentMethod::Bkash, 'created_at' => now()]);
    Payment::factory()->for($refundable)->paid()->create(['method' => PaymentMethod::Bkash]);
    Order::factory()->create(['customer_name' => 'Karim Fresh', 'created_at' => now()->subHours(23)]);

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(NeedsAttentionTable::class)
        /** Lazy, so the page carries only its mount; the filter comes before it now. */
        ->assertSeeInOrder(['Reporting Window', 'wire:name="'.NeedsAttentionTable::class.'"'], escape: false);

    $dashboard = app(Dashboard::class);
    $widgets = array_values($dashboard->getWidgets());

    /**
     * The header slot is gone rather than handed on: whichever widget sorted
     * first would have inherited it, and every other widget on this screen
     * reads the filter the slot is drawn above.
     */
    $headerWidgets = (fn (): array => $this->getHeaderWidgets())->call($dashboard);

    expect($headerWidgets)->toBe([])
        ->and(array_search(DeliveryMixTable::class, $widgets, true))
        ->toBeLessThan(array_search(NeedsAttentionTable::class, $widgets, true))
        ->and(array_search(NeedsAttentionTable::class, $widgets, true))
        ->toBeLessThan(array_search(PageLeaderboardTable::class, $widgets, true))
        ->and(app(NeedsAttentionTable::class)->getColumnSpan())->toBe('full');

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee('Needs Attention')
        /** Drawn under the filter now, so the subheading is what says it is not filtered. */
        ->assertSee('whatever the Reporting Window above says')
        ->assertSee($uncalled->order_number)
        ->assertSee('Rahim Uddin')
        ->assertSee('New for over a day')
        ->assertSee('Call the Customer')
        ->assertSee($refundable->order_number)
        ->assertSee('Paid and Cancelled')
        ->assertSee(OrderResource::getUrl('view', ['record' => $uncalled]))
        ->assertSee(OrderResource::getUrl('view', ['record' => $refundable]))
        ->assertDontSee('Karim Fresh')
        ->assertDontSee('Nothing needs attention');
});

it('says plainly that nothing needs attention when the list is empty, whatever the window', function () {
    Order::factory()->create(['created_at' => now()->subHours(23)]);

    Livewire::test(NeedsAttentionTable::class)
        ->assertSee('Needs Attention')
        ->assertSee('Nothing needs attention');
});

it('draws Top Products and the Delivery mix side by side under the Payment Method mix, each obeying the filter', function () {
    Page::factory()->published()->create();

    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);
    $mug = Product::factory()->create(['name' => 'Clay mug', 'price' => 10000]);

    $today = Order::factory()->create(['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000, 'customer_district' => 'Dhaka', 'created_at' => now()]);
    $earlier = Order::factory()->create(['delivery_label' => 'Outside Dhaka', 'delivery_charge' => 12000, 'customer_district' => 'Sylhet', 'created_at' => now()->subDays(3)]);

    OrderLine::factory()->for($today)->create(['product_id' => $tea->id, 'product_name' => $tea->name, 'unit_price' => $tea->price, 'quantity' => 2]);
    OrderLine::factory()->for($earlier)->create(['product_id' => $mug->id, 'product_name' => $mug->name, 'unit_price' => $mug->price, 'quantity' => 1]);

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(TopProductsTable::class)
        ->assertSeeLivewire(DeliveryMixTable::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(PaymentMethodMixChart::class, $widgets, true))
        ->toBeLessThan(array_search(TopProductsTable::class, $widgets, true))
        ->and(array_search(TopProductsTable::class, $widgets, true))
        ->toBeLessThan(array_search(DeliveryMixTable::class, $widgets, true))
        ->and(array_search(DeliveryMixTable::class, $widgets, true))
        ->toBeLessThan(array_search(CachePathIndicator::class, $widgets, true))
        ->and(app(TopProductsTable::class)->getColumnSpan())->toBe(1)
        ->and(app(DeliveryMixTable::class)->getColumnSpan())->toBe(1);

    $symbol = app(GeneralSettings::class)->currencySymbol;

    /**
     * The counting is proved against the module in ReportingProductRankingTest
     * and ReportingDeliveryMixTest; what is pinned here is that each table is
     * on the screen with its money in the installation's currency, and that
     * the Reporting Window changes what it says.
     */
    Livewire::test(TopProductsTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Top Products')
        ->assertSee('Units sold')
        ->assertSee('Sylhet tea')
        ->assertSee($symbol.'600.00')
        ->assertSee('Clay mug');

    Livewire::test(TopProductsTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSee('Sylhet tea')
        ->assertDontSee('Clay mug');

    Livewire::test(DeliveryMixTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Delivery mix')
        ->assertSee('Inside Dhaka')
        ->assertSee('Outside Dhaka')
        ->assertSee($symbol.'120.00')
        ->assertSee('Most Orders to Dhaka (1), Sylhet (1)');

    Livewire::test(DeliveryMixTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSee('Inside Dhaka')
        ->assertDontSee('Outside Dhaka');
});

it('keeps the Cache Path indicator on the Dashboard, below the reports', function () {
    Page::factory()->published()->create();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(CachePathIndicator::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(OverviewStats::class, $widgets, true))
        ->toBeLessThan(array_search(CachePathIndicator::class, $widgets, true));
});

it('pages the Page leaderboard at ten rows, sorting the whole leaderboard before the page is cut, and returning to the first page when the Reporting Window changes', function () {
    /**
     * Twelve Pages ranked by the Sales each was given, so there is a second
     * page at all. The lowest-ranked of them is the one carrying the most
     * Orders — it sits on page two, which is where a sort by Orders has to
     * reach to be a sort of the leaderboard rather than of the page in view.
     */
    $pages = collect(range(1, 12))->map(function (int $rank): Page {
        $page = Page::factory()->published()->create(['title' => "Combo {$rank}"]);

        Order::factory()->create([
            'page_id' => $page->getKey(),
            'subtotal' => (13 - $rank) * 10000,
            'delivery_charge' => 0,
            'created_at' => now(),
        ]);

        return $page;
    });

    Order::factory()->count(2)->create([
        'page_id' => $pages->last()->getKey(),
        'subtotal' => 100,
        'delivery_charge' => 0,
        'created_at' => now(),
    ]);

    $leaderboard = Livewire::test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']]);

    $records = fn (): LengthAwarePaginator => $leaderboard->instance()->getTableRecords();
    $titles = fn (): array => $records()->getCollection()->pluck('title')->values()->all();

    expect($records())->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($records()->total())->toBe(12)
        ->and($records()->perPage())->toBe(10)
        ->and($records()->lastPage())->toBe(2)
        ->and($titles())->toHaveCount(10)
        ->and($titles()[0])->toBe('Combo 1')
        ->and($titles())->not->toContain('Combo 12');

    $leaderboard->call('setPage', 2);

    expect($records()->currentPage())->toBe(2)
        ->and($titles())->toBe(['Combo 11', 'Combo 12']);

    $leaderboard->sortTable('orders', 'desc');

    expect($records()->currentPage())->toBe(1)
        ->and($titles()[0])->toBe('Combo 12');

    /**
     * A new window is a new question, so it is answered from the top.
     *
     * Livewire refuses a lifecycle method over the wire ("Unable to call
     * lifecycle method [updatedPageFilters] directly"), so the hook is
     * invoked where Livewire invokes it — on the component itself, once the
     * reactive property it watches has changed.
     */
    $leaderboard->call('setPage', 2);

    $instance = $leaderboard->instance();

    expect($instance->getTablePage())->toBe(2);

    $instance->updatedPageFilters();

    expect($instance->getTablePage())->toBe(1);
});

it('pages every collection-backed report table at ten rows, offering the standard page sizes', function () {
    $page = Page::factory()->published()->create();

    $order = Order::factory()->create([
        'page_id' => $page->getKey(),
        'delivery_label' => 'Inside Dhaka',
        'delivery_charge' => 6000,
        'created_at' => now(),
    ]);

    OrderLine::factory()->for($order)->create();

    /**
     * Each of these is backed by a Collection of rows rather than a query, so
     * each has to hand Filament a paginator itself for page controls to exist
     * at all. What is pinned is that every one of them does, at the same page
     * size, with the same sizes on offer.
     */
    foreach ([NeedsAttentionTable::class, PageLeaderboardTable::class, TopProductsTable::class, DeliveryMixTable::class] as $widget) {
        $instance = Livewire::test($widget)->instance();

        expect($instance->getTable()->isPaginated())->toBeTrue()
            ->and($instance->getTable()->getPaginationPageOptions())->toBe([5, 10, 25, 50])
            ->and($instance->getTableRecordsPerPage())->toBe(10)
            ->and($instance->getTableRecords())->toBeInstanceOf(LengthAwarePaginator::class);
    }
});

it('answers a page past the end of a report table with its last page, so a stale page number never reads as no data', function () {
    /**
     * Three Orders nobody has called, and a page number that has outlived the
     * rows it was counted against — which is what a bookmarked `?page=`, a
     * refresh, or a backlog worked down while the page stayed open all leave
     * behind. Every widget table reads that same key, because none of them
     * names its own, so the one that must never be wrong is the one asserted:
     * an empty page under the green tick is a false all-clear.
     */
    Order::factory()->count(3)->create(['created_at' => now()->subHours(25)]);

    $attention = Livewire::withQueryParams(['page' => 3])->test(NeedsAttentionTable::class);

    $attention->assertSee('Needs Attention')
        ->assertDontSee('Nothing needs attention');

    /**
     * The stale number did reach the table — so this is the clamp being
     * asserted and not the query string quietly failing to arrive.
     */
    expect($attention->instance()->getTablePage())->toBe(3)
        ->and($attention->instance()->getTableRecords()->currentPage())->toBe(1)
        ->and($attention->instance()->getTableRecords()->count())->toBe(3);

    /** The same clamp on a table with a real second page: the last one, not nothing. */
    collect(range(1, 12))->each(function (int $rank): void {
        $page = Page::factory()->published()->create(['title' => "Combo {$rank}"]);

        Order::factory()->create([
            'page_id' => $page->getKey(),
            'subtotal' => (13 - $rank) * 10000,
            'delivery_charge' => 0,
            'created_at' => now(),
        ]);
    });

    $leaderboard = Livewire::withQueryParams(['page' => 99])
        ->test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']]);

    expect($leaderboard->instance()->getTableRecords()->currentPage())->toBe(2)
        ->and($leaderboard->instance()->getTableRecords()->count())->toBeGreaterThan(0);
});
