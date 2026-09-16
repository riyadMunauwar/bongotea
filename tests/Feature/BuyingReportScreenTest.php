<?php

use App\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Filament\Clusters\Reports\Pages\BuyingReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\BuyingByProductTable;
use App\Filament\Clusters\Reports\Widgets\BuyingBySupplierTable;
use App\Filament\Clusters\Reports\Widgets\BuyingTotalsStat;
use App\Filament\Pages\Dashboard;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Livewire\Livewire;

/**
 * The report sits behind `view reports` now (#161), so the User opening it
 * holds that Permission. It also links each Supplier row into their deliveries
 * in the Inventory cluster, behind `count stock`, so a User meant to follow
 * that link holds both.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->viewingReports()->countingStock()->create());
});

/*
| The counting is proved against the query module in ReportingBuyingTest. What
| is asserted here is the screen a Merchant opens: that it carries the
| Reporting Window — unlike the Stock report beside it — that the window it is
| set to is the window the figures obey, that a Voided delivery is on none of
| it, that a Supplier's own buying history is one click from their row, and
| that none of it has leaked onto the Dashboard.
|
| Every delivery below is dated by a day in the Merchant's own timezone, which
| is what supplies.supplied_on is keyed by and what the window is cut from. A
| UTC date would fall on the wrong side of midnight for the six hours a day
| Dhaka is already tomorrow.
*/

/**
 * A day the Merchant would call by that name, as a Supply is dated.
 */
function merchantDay(int $daysAgo = 0): string
{
    return now()->setTimezone(app(GeneralSettings::class)->timezone)->subDays($daysAgo)->toDateString();
}

/**
 * One delivery through the ledger, the way a User records one.
 *
 * @param  list<array{Product, int, int}>  $lines  Product, quantity, unit cost.
 */
function deliveryRecorded(Supplier $supplier, string $day, array $lines): Supply
{
    return app(StockLedger::class)->recordSupply(
        supplier: $supplier,
        suppliedOn: $day,
        lines: array_map(
            fn (array $line): array => [
                'product_id' => $line[0]->getKey(),
                'quantity' => $line[1],
                'unit_cost' => $line[2],
            ],
            $lines,
        ),
    );
}

/**
 * A week of buying: Dhaka Traders delivered twice — ten tea at 200.00 three
 * days ago and six more at 220.00 today — and Sylhet Supply once, five mugs at
 * 140.00 two days ago. A fourth delivery is a fortnight old and belongs to no
 * window this screen opens on.
 *
 * @return array{Supplier, Supplier, Product, Product}
 */
function aWeekOfDeliveries(): array
{
    $dhaka = Supplier::factory()->create(['name' => 'Dhaka Traders']);
    $sylhet = Supplier::factory()->create(['name' => 'Sylhet Supply']);

    $tea = Product::factory()->create(['name' => 'Sylhet tea']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);

    deliveryRecorded($dhaka, merchantDay(3), [[$tea, 10, 20000]]);
    deliveryRecorded($dhaka, merchantDay(), [[$tea, 6, 22000]]);
    deliveryRecorded($sylhet, merchantDay(2), [[$mug, 5, 14000]]);

    deliveryRecorded($dhaka, merchantDay(14), [[$tea, 100, 20000]]);

    return [$dhaka, $sylhet, $tea, $mug];
}

/**
 * The rows a report widget hands its table, read off the widget rather than
 * scraped out of the HTML.
 *
 * @return list<array<string, mixed>>
 */
function buyingReportRows(string $widget, string $preset = 'last_7_days'): array
{
    return (fn (): array => $this->rows()->values()->all())->call(
        Livewire::test($widget, ['pageFilters' => [ReportingWindow::PRESET_FILTER => $preset]])->instance(),
    );
}

/**
 * @return list<array{string, int, int, int}>
 */
function buyingSupplierRows(string $preset = 'last_7_days'): array
{
    return array_map(
        fn (array $row): array => [$row['supplier'], $row['deliveries'], $row['units'], $row['cost']],
        buyingReportRows(BuyingBySupplierTable::class, $preset),
    );
}

/**
 * @return list<array{string, int, int|null, int}>
 */
function buyingProductRows(string $preset = 'last_7_days'): array
{
    return array_map(
        fn (array $row): array => [$row['product'], $row['units'], $row['average_unit_cost'], $row['cost']],
        buyingReportRows(BuyingByProductTable::class, $preset),
    );
}

it('opens the Buying report at an address of its own, with the Reporting Window above the figures', function () {
    expect(BuyingReport::getUrl())->toEndWith('/admin/reports/buying-report');

    $this->get(BuyingReport::getUrl())
        ->assertOk()
        ->assertSeeLivewire(BuyingBySupplierTable::class)
        ->assertSeeLivewire(BuyingByProductTable::class)
        ->assertSeeInOrder([
            'Reporting Window',
            'wire:name="'.BuyingBySupplierTable::class.'"',
        ], escape: false);

    /** Lazy, so the shell renders before the query. */
    expect(BuyingBySupplierTable::isLazy())->toBeTrue();
});

it('sits after the Stock report inside the cluster, carrying no glyph of its own', function () {
    expect(BuyingReport::getCluster())->toBe(ReportsCluster::class)
        ->and(BuyingReport::getNavigationSort())->toBe(2)
        ->and(BuyingReport::getNavigationIcon())->toBeNull();
});

it('opens for any User of the panel, holding no permission of its own', function () {
    expect(BuyingReport::canAccess())->toBeTrue();
});

it('opens on the last seven days and reveals the two pickers once Custom is chosen', function () {
    Livewire::test(BuyingReport::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'last_7_days'], 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'custom')
        ->assertSchemaComponentVisible(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentVisible(ReportingWindow::UNTIL_FILTER, 'filtersForm');
});

it('reads the window by Supplier, biggest spend first, and by Product beside it', function () {
    aWeekOfDeliveries();

    expect(buyingSupplierRows())->toBe([
        ['Dhaka Traders', 2, 16, 332000],
        ['Sylhet Supply', 1, 5, 70000],
    ]);

    expect(buyingProductRows())->toBe([
        ['Sylhet tea', 16, 20750, 332000],
        ['Clay mug', 5, 14000, 70000],
    ]);

    /** Drawn as well as counted: a row's own link is evaluated as the table renders, not when the rows are built. */
    Livewire::test(BuyingBySupplierTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Bought by Supplier')
        ->assertSee('Dhaka Traders')
        ->assertSee(SupplyResource::deliveriesFromUrl(Supplier::where('name', 'Dhaka Traders')->sole()));

    Livewire::test(BuyingByProductTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Bought by Product')
        ->assertSee('Sylhet tea')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'207.50');
});

it('adds the window up across the top', function () {
    aWeekOfDeliveries();

    Livewire::test(BuyingTotalsStat::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Spent')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'4020.00')
        ->assertSee('Units bought')
        ->assertSee('21')
        ->assertSee('Deliveries');
});

it('leaves a Voided delivery out of every figure on the screen', function () {
    [$dhaka, , $tea] = aWeekOfDeliveries();

    $mistake = deliveryRecorded($dhaka, merchantDay(1), [[$tea, 500, 20000]]);

    expect(buyingSupplierRows()[0])->toBe(['Dhaka Traders', 3, 516, 10332000]);

    app(StockLedger::class)->voidSupply($mistake);

    expect(buyingSupplierRows())->toBe([
        ['Dhaka Traders', 2, 16, 332000],
        ['Sylhet Supply', 1, 5, 70000],
    ]);
});

it('narrows every figure when the window narrows, and keeps the choice for the next visit', function () {
    aWeekOfDeliveries();

    Livewire::test(BuyingReport::class)
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'today');

    /** Kept in the session, so the days a Merchant was reading are the days the screen reopens on. */
    Livewire::test(BuyingReport::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'today'], 'filtersForm');

    expect(buyingSupplierRows('today'))->toBe([['Dhaka Traders', 1, 6, 132000]]);
});

it('reads a window that bought nothing as an empty table rather than an error', function () {
    aWeekOfDeliveries();

    Livewire::test(BuyingBySupplierTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'yesterday']])
        ->assertOk()
        ->assertSee('Nothing bought in this window');

    expect(buyingSupplierRows('yesterday'))->toBe([]);
});

it('opens a Supplier\'s own buying history from their row on the report', function () {
    [$dhaka, $sylhet] = aWeekOfDeliveries();

    $url = buyingReportRows(BuyingBySupplierTable::class)[0]['url'];

    expect($url)->toBe(SupplyResource::deliveriesFromUrl($dhaka));

    /** Parsed back into the list the way a browser arrives at it, which is the only way to prove the filter landed. */
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)
        ->test(ListSupplies::class)
        ->assertCanSeeTableRecords($dhaka->supplies)
        ->assertCanNotSeeTableRecords($sylhet->supplies);
});

it('opens a list of the deliveries the figure was counted from, and never one voided row longer', function () {
    [$dhaka, , $tea] = aWeekOfDeliveries();

    $struckOut = deliveryRecorded($dhaka, merchantDay(1), [[$tea, 500, 20000]]);
    app(StockLedger::class)->voidSupply($struckOut);

    parse_str((string) parse_url(SupplyResource::deliveriesFromUrl($dhaka), PHP_URL_QUERY), $query);

    /**
     * Three deliveries stand and a fourth was struck out. Every figure that
     * links here counts the three, so the list opens on the three: a count of
     * three over a list of four is the one thing this address must not do.
     */
    Livewire::withQueryParams($query)
        ->test(ListSupplies::class)
        ->assertCanSeeTableRecords($dhaka->supplies()->notVoided()->get())
        ->assertCanNotSeeTableRecords([$struckOut]);
});

it('opens the same history from the Suppliers list, where the deliveries are counted', function () {
    [$dhaka] = aWeekOfDeliveries();

    Livewire::test(ListSuppliers::class)
        ->assertSee(SupplyResource::deliveriesFromUrl($dhaka));
});

it('opens the same history from the Supplier themselves, not only from the row', function () {
    [$dhaka] = aWeekOfDeliveries();

    $this->get(SupplierResource::getUrl('edit', ['record' => $dhaka]))
        ->assertOk()
        ->assertSee('Deliveries')
        ->assertSee(SupplyResource::deliveriesFromUrl($dhaka));
});

it('keeps its windowed figures off the Dashboard', function () {
    aWeekOfDeliveries();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSeeLivewire(BuyingBySupplierTable::class)
        ->assertDontSeeLivewire(BuyingByProductTable::class)
        ->assertDontSeeLivewire(BuyingTotalsStat::class);

    expect(app(BuyingReport::class)->getWidgets())->toBe([
        BuyingTotalsStat::class,
        BuyingBySupplierTable::class,
        BuyingByProductTable::class,
    ]);
});
