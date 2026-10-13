<?php

use App\Enums\OrderKind;
use App\Filament\Clusters\Reports\Pages\BuyingReport;
use App\Filament\Clusters\Reports\Pages\MarginReport;
use App\Filament\Pages\Dashboard;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Kilograms;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Livewire\Livewire;

/*
| Reports count bulk and retail Orders together unless the Merchant narrows
| them to one (ADR-0034), and Kilograms Sold counts a Pack by its weight, so
| it may fall between whole kilograms (ADR-0035).
|
| Throughout, inside Last 7 days as reportingNow() cuts it: a bulk Order of
| two kilograms of Assam, and a retail Order of three 250 g Packs of the
| same tea, which is 0.75 kg.
*/

beforeEach(function () {
    $this->travelTo(reportingNow());

    $this->category = Category::factory()->create();
    $this->assam = Product::factory()->for($this->category)->create(['name' => 'Assam', 'price' => 50000]);

    $this->bulk = orderCapturedAt('2026-09-08 11:00');
    OrderLine::factory()->for($this->bulk)->for($this->assam)->create(['product_name' => 'Assam', 'unit_price' => 50000, 'unit_cost' => 40000, 'quantity' => 2]);

    $pack = Pack::factory()->for($this->assam)->create(['grams' => 250, 'mrp' => 15000, 'price' => 14000]);
    $this->retail = orderCapturedAt('2026-09-08 12:00', attributes: ['kind' => OrderKind::Retail]);
    OrderLine::factory()->for($this->retail)->ofPack($pack, 3)->create(['unit_cost' => 10000]);
});

function windowFor(?OrderKind $kind): ReportingWindow
{
    return ReportingWindow::of(ReportingPreset::Last7Days, reportingNow())->forKind($kind);
}

it('counts both kinds by default, and only the one asked for when narrowed', function (?OrderKind $kind, Closure $orders) {
    $expected = $orders($this->bulk->refresh(), $this->retail->refresh());
    $figures = app(ReportingQuery::class)->overview(windowFor($kind))->figures;

    expect($figures->orders)->toBe(count($expected))
        ->and($figures->sales)->toBe(array_sum(array_map(fn (Order $order): int => $order->total, $expected)));
})->with([
    'all' => [null, fn (Order $bulk, Order $retail): array => [$bulk, $retail]],
    'bulk' => [OrderKind::Bulk, fn (Order $bulk, Order $retail): array => [$bulk]],
    'retail' => [OrderKind::Retail, fn (Order $bulk, Order $retail): array => [$retail]],
]);

it('counts a Pack by its weight in Kilograms Sold, with decimals where they fall', function () {
    expect(app(ReportingQuery::class)->overview(windowFor(null))->figures->kilograms)->toBe(2.75)
        ->and(app(ReportingQuery::class)->overview(windowFor(OrderKind::Retail))->figures->kilograms)->toBe(0.75)
        ->and(app(ReportingQuery::class)->overview(windowFor(OrderKind::Bulk))->figures->kilograms)->toBe(2)
        ->and(Kilograms::format(2.75))->toBe('2.75 kg')
        ->and(Kilograms::format(1250))->toBe('1,250 kg');
});

it('includes Pack lines in the Margin Report, Sales by Category and the product ranking', function () {
    $window = windowFor(null);
    $query = app(ReportingQuery::class);

    $margin = $query->margin($window);
    $row = $margin->rows[0];

    expect($margin->units)->toBe(2.75)
        ->and($row->units)->toBe(2.75)
        ->and($row->revenue)->toBe(100000 + 42000)
        ->and($row->cost)->toBe(80000 + 30000)
        ->and($margin->everythingCosted())->toBeTrue()
        ->and($query->salesByCategory($window)->rows[0]->kilograms)->toBe(2.75)
        ->and($query->productRanking($window)->rows[0]->units)->toBe(2.75)
        ->and($query->productRanking(windowFor(OrderKind::Retail))->rows[0]->units)->toBe(0.75);
});

it('reads the kind from a screen\'s filters, and keeps it for the comparison window', function () {
    $window = ReportingWindow::fromFilters([ReportingWindow::KIND_FILTER => 'retail'], reportingNow());

    expect($window->kind)->toBe(OrderKind::Retail)
        ->and($window->comparison()->kind)->toBe(OrderKind::Retail)
        ->and(ReportingWindow::fromFilters([], reportingNow())->kind)->toBeNull()
        ->and(ReportingWindow::fromFilters([ReportingWindow::KIND_FILTER => 'nonsense'], reportingNow())->kind)->toBeNull();
});

it('offers the kind beside the window on the screens that count Orders, and not on Buying', function () {
    $this->actingAs(User::factory()->owner()->create());

    Livewire::test(Dashboard::class)->assertSee('Bulk and retail');
    Livewire::test(MarginReport::class)->assertSee('Bulk and retail');
    Livewire::test(BuyingReport::class)->assertDontSee('Bulk and retail');
});
