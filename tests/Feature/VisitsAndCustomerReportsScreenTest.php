<?php

use App\Enums\PlaceKind;
use App\Filament\Clusters\Reports\Pages\CustomerReport;
use App\Filament\Clusters\Reports\Pages\VisitsReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\CustomersTable;
use App\Filament\Clusters\Reports\Widgets\VisitsByPlaceTable;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Widgets\SalesByCategoryTable;
use App\Filament\Widgets\TopCustomersTable;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlaceVisitCount;
use App\Models\Product;
use App\Models\User;
use App\Models\VisitCount;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\TrackingSettings;
use Livewire\Livewire;

/*
| The screens a Merchant opens for Sales by Category, Top Customers, and the
| Visits and Customer Reports. The counting is proved against the query module
| in the Reporting* tests; what is asserted here is what is drawn.
*/
beforeEach(function () {
    $this->actingAs(User::factory()->viewingReports()->workingOrders()->create());
});

const TODAYS_WINDOW = [ReportingWindow::PRESET_FILTER => 'today'];

it('draws each category\'s sales and says where the delivery charges went', function () {
    $gold = Product::factory()->for(Category::factory()->create(['name' => 'Gold']))->create();

    orderAsCaptured(lines: [['product' => $gold, 'kilograms' => 3]]);

    Livewire::test(SalesByCategoryTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->assertSeeInOrder(['Sales by Category', 'Delivery Charges', 'belong to no Category', 'Gold', '1', '100.0%', 'Up from none']);
});

it('draws the top customers, each opening their orders by phone', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->create(['customer_name' => 'Rahim Store', 'created_at' => now()]);
    OrderLine::factory()->for($order)->create(['quantity' => 12]);

    Livewire::test(TopCustomersTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->assertSeeInOrder([$customer->phone, 'Rahim Store', 'New', '1'])
        ->assertSee(OrderResource::byPhoneUrl($customer->phone));
});

/**
 * The link opens the All tab searched by the phone, so a Customer's
 * confirmed and Cancelled Orders are in the list as well as their New ones.
 */
it('opens a customer\'s orders on every tab, searched by their phone', function () {
    $customer = Customer::factory()->create();
    $theirs = Order::factory()->for($customer)->confirmed()->create();
    $someoneElses = Order::factory()->create();

    parse_str((string) parse_url(OrderResource::byPhoneUrl($customer->phone), PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)
        ->test(ListOrders::class)
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('opens the visits report with every place, its buy rate and the visits no place accounts for', function () {
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();

    $gold = Product::factory()->create(['name' => 'Gold Blend']);
    VisitCount::factory()->on(0, 50)->create();
    PlaceVisitCount::factory()->of($gold)->on(0, 8)->create();
    OrderLine::factory()->for(Order::factory()->create(['created_at' => now()]))->create(['product_id' => $gold->getKey()]);

    $this->get(VisitsReport::getUrl())->assertOk();

    Livewire::test(VisitsByPlaceTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->assertSee('Not matched to a place: 42 of 50 Visits')
        ->assertSeeInOrder(['Gold Blend', 'Product', '8', '16.0%', 'Up from none', '1', '12.5%']);
});

/**
 * Sorted by Buy Rate, the whole list is ranked before the page is cut, and a
 * place with no Buy Rate sorts below every one that has one.
 */
it('sorts the visits report by buy rate, places without one last', function () {
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();

    $browsed = Product::factory()->create(['name' => 'Browsed Often']);
    $bought = Product::factory()->create(['name' => 'Bought Often']);
    PlaceVisitCount::factory()->on(0, 90)->create();
    PlaceVisitCount::factory()->of($browsed)->on(0, 40)->create();
    PlaceVisitCount::factory()->of($bought)->on(0, 4)->create();
    OrderLine::factory()->for(Order::factory()->create(['created_at' => now()]))->create(['product_id' => $bought->getKey()]);

    Livewire::test(VisitsByPlaceTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->sortTable('buy_rate', 'desc')
        ->assertSeeInOrder(['Bought Often', 'Browsed Often', 'Search']);
});

it('opens the customer report with everybody who bought', function () {
    $customers = Customer::factory()->count(12)->create();

    foreach ($customers as $customer) {
        Order::factory()->for($customer)->create(['created_at' => now()]);
    }

    $this->get(CustomerReport::getUrl())->assertOk();

    Livewire::test(CustomersTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->assertSee('Customers')
        ->assertCountTableRecords(12);
});

it('sits visits then customers after margin in the reports cluster', function () {
    expect(VisitsReport::getCluster())->toBe(ReportsCluster::class)
        ->and(CustomerReport::getCluster())->toBe(ReportsCluster::class)
        ->and(VisitsReport::getNavigationSort())->toBe(4)
        ->and(CustomerReport::getNavigationSort())->toBe(5);
});

/**
 * Two deleted Categories both had Visits: each is its own row, told apart by
 * the id they were counted under though neither has a record left.
 */
it('keeps two deleted places apart', function () {
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();

    PlaceVisitCount::factory()->on(0, 3)->create(['place_kind' => PlaceKind::Category, 'place_id' => 901]);
    PlaceVisitCount::factory()->on(0, 2)->create(['place_kind' => PlaceKind::Category, 'place_id' => 902]);

    Livewire::test(VisitsByPlaceTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->assertCountTableRecords(2);
});

/**
 * Narrowing the window from a later page starts the list from the top, or
 * the table would ask for a page the shorter list no longer has.
 */
it('starts the visits report from the top when the window changes', function () {
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();

    foreach (Product::factory()->count(12)->create() as $product) {
        PlaceVisitCount::factory()->of($product)->on(0, 5)->create();
    }

    /** Called on the component, where Livewire calls it: it refuses a lifecycle method over the wire. */
    $instance = Livewire::test(VisitsByPlaceTable::class, ['pageFilters' => TODAYS_WINDOW])
        ->call('setPage', 2)
        ->instance();

    expect($instance->getTablePage())->toBe(2);

    $instance->updatedPageFilters();

    expect($instance->getTablePage())->toBe(1);
});

it('keeps the full reports off the dashboard', function () {
    expect(app(Dashboard::class)->getWidgets())
        ->not->toContain(VisitsByPlaceTable::class)
        ->not->toContain(CustomersTable::class);
});
