<?php

use App\Enums\OrderStatus;
use App\Filament\Clusters\Reports\Pages\MarginReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\MarginByProductTable;
use App\Filament\Clusters\Reports\Widgets\MarginTotalsStat;
use App\Filament\Pages\Dashboard;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Livewire\Livewire;

/**
 * The report sits behind `view reports` now (#161), so the User opening it
 * holds that Permission.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->viewingReports()->create());
});

/*
| The counting is proved against the query module in ReportingMarginTest. What
| is asserted here is the screen a Merchant opens: that it carries the
| Reporting Window, that the window it is set to is the window the figures
| obey, that a Product nobody has priced reads as unknown rather than as all
| profit, and that none of it has leaked onto the Dashboard.
|
| Orders are dated in the Merchant's own timezone so a window cut on whole
| days falls where a Merchant would put it.
*/

/**
 * A sale with a cost stamped on it, the way Capture writes one. A null cost
 * is the unknown a Product nothing has been recorded buying stamps.
 */
function soldDaysAgo(Product $product, int $daysAgo, int $quantity, ?int $unitCost, OrderStatus $status = OrderStatus::New): OrderLine
{
    $factory = $status === OrderStatus::Cancelled ? Order::factory()->cancelled() : Order::factory();

    $order = $factory->create([
        'delivery_charge' => 6000,
        'created_at' => now()->setTimezone(app(GeneralSettings::class)->timezone)->subDays($daysAgo)->setTime(12, 0)->utc(),
    ]);

    return OrderLine::factory()->for($order)->create([
        'product_id' => $product->getKey(),
        'product_name' => $product->name,
        'unit_price' => $product->price,
        'unit_cost' => $unitCost,
        'quantity' => $quantity,
    ]);
}

/**
 * A week of selling with costs beside it: three tea at 300.00 costing 180.00
 * each, one mug at 100.00 costing 90.00, and a rug at 900.00 nobody has ever
 * recorded buying. A fortnight-old sale belongs to no window this screen opens
 * on.
 *
 * @return array{Product, Product, Product}
 */
function aWeekOfMargin(): array
{
    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);
    $mug = Product::factory()->create(['name' => 'Clay mug', 'price' => 10000]);
    $rug = Product::factory()->create(['name' => 'Rangpur rug', 'price' => 90000]);

    soldDaysAgo($tea, 3, 2, 18000);
    soldDaysAgo($tea, 0, 1, 18000);
    soldDaysAgo($mug, 2, 1, 9000);
    soldDaysAgo($rug, 1, 1, null);

    soldDaysAgo($tea, 14, 100, 18000);

    return [$tea, $mug, $rug];
}

/**
 * The rows the report widget hands its table, read off the widget rather than
 * scraped out of the HTML.
 *
 * @return list<array{string, int, int, int|null, int|null}>
 */
function marginReportRows(string $preset = 'last_7_days'): array
{
    $rows = (fn (): array => $this->rows()->values()->all())->call(
        Livewire::test(MarginByProductTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => $preset]])->instance(),
    );

    return array_map(
        fn (array $row): array => [$row['product'], $row['units'], $row['revenue'], $row['cost'], $row['margin']],
        $rows,
    );
}

it('opens the Margin report at an address of its own, with the Reporting Window above the figures', function () {
    expect(MarginReport::getUrl())->toEndWith('/admin/reports/margin-report');

    $this->get(MarginReport::getUrl())
        ->assertOk()
        ->assertSeeLivewire(MarginTotalsStat::class)
        ->assertSeeLivewire(MarginByProductTable::class)
        ->assertSeeInOrder([
            'Reporting Window',
            'wire:name="'.MarginByProductTable::class.'"',
        ], escape: false);

    /** Lazy, so the shell renders before the query. */
    expect(MarginByProductTable::isLazy())->toBeTrue();
});

it('sits after the Buying report inside the cluster, carrying no glyph of its own', function () {
    expect(MarginReport::getCluster())->toBe(ReportsCluster::class)
        ->and(MarginReport::getNavigationSort())->toBe(3)
        ->and(MarginReport::getNavigationIcon())->toBeNull();
});

it('opens for any User of the panel, holding no permission of its own', function () {
    expect(MarginReport::canAccess())->toBeTrue();
});

it('opens on the last seven days and reveals the two pickers once Custom is chosen', function () {
    Livewire::test(MarginReport::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'last_7_days'], 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'custom')
        ->assertSchemaComponentVisible(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentVisible(ReportingWindow::UNTIL_FILTER, 'filtersForm');
});

it('ranks the window by what each Product made, not by what it turned over', function () {
    aWeekOfMargin();

    /** The rug turned over the most of anything and is last, because nobody knows what it made. */
    expect(marginReportRows())->toBe([
        ['Sylhet tea', 3, 90000, 54000, 36000],
        ['Clay mug', 1, 10000, 9000, 1000],
        ['Rangpur rug', 1, 90000, null, null],
    ]);

    Livewire::test(MarginByProductTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Earned by Product')
        ->assertSeeInOrder(['Sylhet tea', 'Clay mug', 'Rangpur rug'])
        ->assertSee('no cost recorded');
});

it('says the revenue across the top is Sales, Delivery Charge and all', function () {
    aWeekOfMargin();

    Livewire::test(MarginTotalsStat::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Revenue')
        ->assertSee('Sales over this window, Delivery Charge included')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'2140.00')
        ->assertSee('Cost of goods')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'630.00')
        ->assertSee('Margin')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'1510.00');
});

it('draws Kilograms Sold beside the revenue, over the same Orders', function () {
    [$tea] = aWeekOfMargin();
    soldDaysAgo($tea, 1, 25, 18000, OrderStatus::Cancelled);

    Livewire::test(MarginTotalsStat::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSeeInOrder(['Revenue', 'Kilograms Sold', '5 kg', 'Cost of goods']);
});

it('admits how much of the window has a cost behind it rather than drawing the margin as a fact', function () {
    aWeekOfMargin();

    Livewire::test(MarginTotalsStat::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('4 of 5 kg carry a stamped cost')
        ->assertSee('At most 70.6% of revenue, with part of the cost unknown');
});

it('reads a window whose sales carry no cost at all as unknown rather than as all profit', function () {
    $rug = Product::factory()->create(['name' => 'Rangpur rug', 'price' => 90000]);
    soldDaysAgo($rug, 1, 1, null);

    Livewire::test(MarginTotalsStat::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Not known')
        ->assertSee('Record a delivery and what sells gets a cost against it');
});

it('leaves a Cancelled Order out of both sides of the window', function () {
    [$tea] = aWeekOfMargin();

    soldDaysAgo($tea, 1, 20, 18000, OrderStatus::Cancelled);

    expect(marginReportRows()[0])->toBe(['Sylhet tea', 3, 90000, 54000, 36000]);
});

it('narrows every figure when the window narrows, and keeps the choice for the next visit', function () {
    aWeekOfMargin();

    Livewire::test(MarginReport::class)
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'today');

    /** Kept in the session, so the days a Merchant was reading are the days the screen reopens on. */
    Livewire::test(MarginReport::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'today'], 'filtersForm');

    expect(marginReportRows('today'))->toBe([['Sylhet tea', 1, 30000, 18000, 12000]]);
});

it('reads a window that sold nothing as an empty table rather than an error', function () {
    /** A fortnight-old sale and nothing since: every preset this screen opens on is empty. */
    soldDaysAgo(Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]), 14, 1, 18000);

    Livewire::test(MarginByProductTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertOk()
        ->assertSee('Nothing sold in this window');

    expect(marginReportRows())->toBe([]);
});

it('keeps its windowed figures off the Dashboard', function () {
    aWeekOfMargin();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSeeLivewire(MarginTotalsStat::class)
        ->assertDontSeeLivewire(MarginByProductTable::class);

    expect(app(MarginReport::class)->getWidgets())->toBe([
        MarginTotalsStat::class,
        MarginByProductTable::class,
    ]);
});
