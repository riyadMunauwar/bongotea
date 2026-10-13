<?php

use App\Enums\AdjustmentCause;
use App\Filament\Clusters\Reports\Pages\CourierReport;
use App\Filament\Clusters\Reports\Pages\StockReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\StockHoldingsTable;
use App\Filament\Clusters\Reports\Widgets\StockValueStat;
use App\Filament\Pages\Dashboard;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\StockLedger;
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
| The counting is proved against the query module in
| ReportingStockHoldingsTest. What is asserted here is the screen a Merchant
| opens — and above all what is *not* on it: this page draws no Reporting
| Window control whatsoever, because every figure on it is as things stand and
| a control that changed nothing would be worse than none.
*/

/**
 * A shelf worth reading: tea counted and delivered, a mug counted and never
 * bought, and a candle nobody has looked at.
 *
 * @return array{Product, Product, Product}
 */
function aShelfOfStock(): array
{
    $tea = Product::factory()->create(['name' => 'Sylhet tea']);
    $mug = Product::factory()->create(['name' => 'Clay mug']);
    $candle = Product::factory()->create(['name' => 'Uncounted candle']);

    app(StockLedger::class)->recordCount($mug, 3000, AdjustmentCause::OpeningCount);

    app(StockLedger::class)->recordSupply(
        supplier: Supplier::factory()->create(['name' => 'Dhaka Traders']),
        suppliedOn: '2026-09-05',
        lines: [['product_id' => $tea->getKey(), 'quantity' => 10000, 'unit_cost' => 20000]],
    );

    return [$tea, $mug, $candle];
}

it('opens the Stock report at an address of its own inside Reports', function () {
    expect(StockReport::getUrl())->toEndWith('/admin/reports/stock-report');

    $this->get(StockReport::getUrl())
        ->assertOk()
        ->assertSeeLivewire(StockHoldingsTable::class)
        ->assertSeeLivewire(StockValueStat::class);

    /** Lazy, so the shell renders before the ledger is read. */
    expect(StockHoldingsTable::isLazy())->toBeTrue();
});

it('draws no Reporting Window control whatsoever, rather than one that changes nothing', function () {
    $this->get(StockReport::getUrl())
        ->assertOk()
        ->assertDontSee('Reporting Window');

    /**
     * Not merely hidden: the screen has no filter state at all, so there is
     * nothing for a later hand to draw by accident — where the Courier Report
     * beside it has both.
     */
    expect(property_exists(StockReport::class, 'filters'))->toBeFalse()
        ->and(method_exists(StockReport::class, 'filtersForm'))->toBeFalse()
        ->and(property_exists(CourierReport::class, 'filters'))->toBeTrue();
});

it('sits after the Courier Report inside the cluster, carrying no glyph of its own', function () {
    expect(StockReport::getCluster())->toBe(ReportsCluster::class)
        ->and(StockReport::getNavigationSort())->toBe(1)
        ->and(StockReport::getNavigationIcon())->toBeNull();
});

it('opens for any User of the panel, holding no permission of its own', function () {
    expect(StockReport::canAccess())->toBeTrue();
});

it('draws what each Product holds and what it is worth', function () {
    aShelfOfStock();

    Livewire::test(StockHoldingsTable::class)
        ->assertSee('Stock held')
        ->assertSee('Sylhet tea')
        ->assertSee('Clay mug')
        ->assertSee('Uncounted candle')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'2000.00');
});

it('reads a Product nobody has counted as nothing, never as zero', function () {
    Product::factory()->create(['name' => 'Uncounted candle']);

    $rows = (fn (): array => $this->rows()->values()->all())->call(Livewire::test(StockHoldingsTable::class)->instance());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['on_hand'])->toBeNull()
        ->and($rows[0]['value_at_cost'])->toBeNull();
});

it('says what is held altogether, and how much of the catalogue that covers', function () {
    aShelfOfStock();

    Livewire::test(StockValueStat::class)
        ->assertSee('Value at cost held')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'2000.00')
        ->assertSee('Products in the ledger')
        ->assertSee('2 of 3');
});

it('says the worth of an installation that has recorded no buying is unknown, never nothing', function () {
    app(StockLedger::class)->recordCount(Product::factory()->create(), 5000, AdjustmentCause::OpeningCount);

    Livewire::test(StockValueStat::class)
        ->assertSee('Not known')
        ->assertDontSee(app(GeneralSettings::class)->currencySymbol.'0.00');
});

it('keeps its figures off the Dashboard, which draws no stock at all', function () {
    aShelfOfStock();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSeeLivewire(StockHoldingsTable::class)
        ->assertDontSeeLivewire(StockValueStat::class);

    expect(app(StockReport::class)->getWidgets())->toBe([
        StockValueStat::class,
        StockHoldingsTable::class,
    ]);
});
