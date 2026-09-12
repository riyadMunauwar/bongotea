<?php

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\PageReport;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PaymentMethodMixChart;
use App\Filament\Widgets\SalesOverTimeChart;
use App\Filament\Widgets\TopProductsTable;
use App\Models\Order;
use App\Models\Page;
use App\Models\User;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/*
| The counting is proved against the query module in ReportingOverviewTest,
| ReportingSalesOverTimeTest, ReportingProductRankingTest and
| ReportingPaymentMixTest. What is asserted here is the screen a Merchant
| opens: that it is reachable at an address of its own, that it draws the
| Dashboard's own widget classes in a stated order, that the Reporting
| Window sits across the top as a form every other Page's Report opens on,
| and that a screen about a Page's past offers no way to destroy the Page.
|
| Two seams, both of them existing. The rendered screen over HTTP is where
| everything provable there is proved; a widget in isolation is driven only
| for what that seam cannot reach — a chart's dataset read as data rather
| than scraped, and the figures a widget reports for a Page and a window.
| How the record reaches a widget, and where the window is kept, are
| Filament's own and are not asserted here.
*/

/** The Report's own address, which is what a Merchant bookmarks. */
function reportUrl(Page $page): string
{
    return PageResource::getUrl('report', ['record' => $page]);
}

it('opens a Page\'s Report at an address of its own under the Pages resource', function () {
    [$reported] = twoReportedPages();

    expect(reportUrl($reported))->toEndWith("/admin/pages/{$reported->getKey()}/report");

    /** Nothing is posted to reach it, so the address is worth sharing. */
    $this->get(reportUrl($reported))->assertOk();
});

it('draws the Reporting Window across the top and then the report, Top Products before the Payment Method mix', function () {
    [$reported] = twoReportedPages();

    $this->get(reportUrl($reported))
        ->assertOk()
        ->assertSeeLivewire(OverviewStats::class)
        ->assertSeeLivewire(SalesOverTimeChart::class)
        ->assertSeeLivewire(TopProductsTable::class)
        ->assertSeeLivewire(PaymentMethodMixChart::class)
        /** Lazy, so the screen carries only each mount; the window's control comes before all of them. */
        ->assertSeeInOrder([
            'Reporting Window',
            'wire:name="'.OverviewStats::class.'"',
            'wire:name="'.SalesOverTimeChart::class.'"',
            'wire:name="'.TopProductsTable::class.'"',
            'wire:name="'.PaymentMethodMixChart::class.'"',
        ], escape: false);

    /**
     * The mount markers are the whole of the proof, and they are enough:
     * the widgets' own sort puts the Payment Method mix above Top Products,
     * so drawing them the other way round can only come from this screen
     * saying so.
     *
     * Each of them lazy, so four aggregate queries never hold up the screen.
     */
    foreach ([OverviewStats::class, SalesOverTimeChart::class, TopProductsTable::class, PaymentMethodMixChart::class] as $widget) {
        expect($widget::isLazy())->toBeTrue();
    }
});

it('reports one Page\'s figures and never another Page\'s', function () {
    [$reported, $other] = twoReportedPages();

    $symbol = app(GeneralSettings::class)->currencySymbol;

    /** Two Orders on this Page, 820.00 between them; the other Page's 960.00 is nowhere. */
    Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee($symbol.'820.00')
        ->assertSee($symbol.'410.00')
        ->assertDontSee($symbol.'960.00');

    Livewire::test(TopProductsTable::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Sylhet tea')
        ->assertSee('Clay mug')
        ->assertDontSee('Rangpur rug');

    $sales = pageChartData(SalesOverTimeChart::class, $reported);

    expect($sales['labels'])->toHaveCount(7)
        ->and($sales['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 160.0, 0.0, 0.0, 660.0])
        ->and($sales['datasets'][1]['data'])->toBe([0, 0, 0, 1, 0, 0, 1]);

    $mix = pageChartData(PaymentMethodMixChart::class, $reported);
    $series = collect($mix['datasets'])->keyBy('label')->map(fn (array $dataset): array => $dataset['data']);

    expect($mix['labels'])->toBe(['Cash on Delivery', 'bKash'])
        ->and($series['Paid'])->toBe([0, 1])
        ->and($series['Due on delivery'])->toBe([1, 0])
        ->and($series['Sales'])->toBe([160.0, 660.0]);

    /** The same classes on the other Page answer with the other Page's figures. */
    Livewire::test(OverviewStats::class, ['record' => $other, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee($symbol.'960.00')
        ->assertDontSee($symbol.'820.00');

    Livewire::test(TopProductsTable::class, ['record' => $other, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Rangpur rug')
        ->assertDontSee('Sylhet tea');
});

it('opens on the last seven days and reveals the two pickers, each bounding the other, once Custom is chosen', function () {
    [$reported] = twoReportedPages();

    Livewire::test(PageReport::class, ['record' => $reported->getKey()])
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'last_7_days'], 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::UNTIL_FILTER, 'filtersForm');

    /**
     * Set as the browser sets a live field, one property update at a time,
     * because that is what Filament persists on; `fillForm()` writes the
     * state through a method call the persistence hook never sees.
     */
    $screen = Livewire::test(PageReport::class, ['record' => $reported->getKey()])
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'custom')
        ->assertSchemaComponentVisible(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentVisible(ReportingWindow::UNTIL_FILTER, 'filtersForm')
        ->set('filters.'.ReportingWindow::FROM_FILTER, '2026-09-01')
        ->set('filters.'.ReportingWindow::UNTIL_FILTER, '2026-09-03');

    /** Neither picker offers a day that would put the window the wrong way round. */
    $filtersForm = $screen->instance()->getSchema('filtersForm');

    expect(fieldInside($filtersForm, ReportingWindow::FROM_FILTER)->getMaxDate())->toBe('2026-09-03')
        ->and(fieldInside($filtersForm, ReportingWindow::UNTIL_FILTER)->getMinDate())->toBe('2026-09-01');
});

it('narrows every widget when the window narrows, and opens every other Page\'s Report on the chosen one', function () {
    [$reported, $other] = twoReportedPages();

    Livewire::test(PageReport::class, ['record' => $reported->getKey()])
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'today');

    /**
     * One window for the screen rather than one per Page, and kept in the
     * session — so the next Page a Merchant reads opens on the days they
     * were already reading, days later.
     */
    Livewire::test(PageReport::class, ['record' => $other->getKey()])
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'today'], 'filtersForm');

    /**
     * Today's window leaves the Order from three days ago out, and every
     * widget says so: the tiles drop 160.00, Top Products loses the mug,
     * Sales over time falls to one bucket an hour wide, and the mix keeps
     * only the method today's Order was paid by.
     */
    $symbol = app(GeneralSettings::class)->currencySymbol;

    Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSee($symbol.'660.00')
        ->assertDontSee($symbol.'820.00');

    Livewire::test(TopProductsTable::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSee('Sylhet tea')
        ->assertDontSee('Clay mug');

    $sales = pageChartData(SalesOverTimeChart::class, $reported, 'today');
    $mix = pageChartData(PaymentMethodMixChart::class, $reported, 'today');

    expect($sales['labels'])->toHaveCount(24)
        ->and(array_sum($sales['datasets'][1]['data']))->toBe(1)
        ->and($mix['labels'])->toBe(['bKash']);
});

it('names the Page in the heading and states Report in the breadcrumb', function () {
    [$reported] = twoReportedPages();

    $screen = Livewire::test(PageReport::class, ['record' => $reported->getKey()])->instance();

    expect($screen->getTitle())->toBe('Summer combo')
        ->and($screen->getBreadcrumbs())->toBe([PageResource::getUrl('index') => 'Pages', 'Report']);

    $this->get(reportUrl($reported))
        ->assertOk()
        ->assertSee('Summer combo')
        ->assertSee('Report');
});

it('offers Edit and Preview, and no way at all to destroy the Page', function () {
    [$reported] = twoReportedPages();

    Livewire::test(PageReport::class, ['record' => $reported->getKey()])
        ->assertActionExists('edit', fn ($action): bool => $action->getUrl() === PageResource::getUrl('edit', ['record' => $reported]))
        ->assertActionExists('preview', fn ($action): bool => $action->getUrl() === route('pages.preview', $reported))
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('restore');
});

it('reads a Page that never took an Order as zeros and an empty chart, not as an error', function () {
    $draft = Page::factory()->create(['title' => 'Never sold']);

    Order::factory()->create(['subtotal' => 90000, 'delivery_charge' => 6000, 'created_at' => now()]);

    $this->get(reportUrl($draft))->assertOk();

    $symbol = app(GeneralSettings::class)->currencySymbol;

    Livewire::test(OverviewStats::class, ['record' => $draft, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertOk()
        ->assertSee($symbol.'0.00')
        ->assertSee('0.0%')
        ->assertDontSee($symbol.'960.00');

    Livewire::test(TopProductsTable::class, ['record' => $draft, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertOk()
        ->assertSee('Nothing sold in this window');

    $sales = pageChartData(SalesOverTimeChart::class, $draft);
    $mix = pageChartData(PaymentMethodMixChart::class, $draft);

    expect($sales['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($sales['datasets'][1]['data'])->toBe([0, 0, 0, 0, 0, 0, 0])
        ->and($mix['labels'])->toBe([])
        ->and($mix['datasets'][0]['data'])->toBe([]);
});

it('still reports on a retired Page, whose Orders were captured before it was taken down', function () {
    [$reported] = twoReportedPages();
    $reported->delete();

    $this->get(reportUrl($reported))
        ->assertOk()
        ->assertSeeLivewire(OverviewStats::class);

    $symbol = app(GeneralSettings::class)->currencySymbol;

    Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee($symbol.'820.00')
        ->assertDontSee($symbol.'960.00');

    Livewire::test(TopProductsTable::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Sylhet tea')
        ->assertDontSee('Rangpur rug');
});
