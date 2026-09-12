<?php

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\EditPage;
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
| The Page scope itself is proved against the query module in
| ReportingOverviewTest, ReportingSalesOverTimeTest, ReportingProductRankingTest
| and ReportingPaymentMixTest. What is asserted here is the screen: that the
| Dashboard's own widget classes are drawn above the form carrying the
| record, that one Page's figures appear and another's do not, and that the
| Reporting Window is offered as a header action the widgets obey.
*/

it('draws the Dashboard\'s own report widgets above the form, in the order the Dashboard draws them', function () {
    [$reported] = twoReportedPages();

    $this->get(PageResource::getUrl('edit', ['record' => $reported]))
        ->assertOk()
        ->assertSeeLivewire(OverviewStats::class)
        ->assertSeeLivewire(SalesOverTimeChart::class)
        ->assertSeeLivewire(TopProductsTable::class)
        ->assertSeeLivewire(PaymentMethodMixChart::class)
        /** Lazy, so the screen carries only each mount; those mounts come before the form. */
        ->assertSeeInOrder([
            'wire:name="'.OverviewStats::class.'"',
            'wire:name="'.SalesOverTimeChart::class.'"',
            'wire:name="'.TopProductsTable::class.'"',
            'wire:name="'.PaymentMethodMixChart::class.'"',
            'data.title',
        ], escape: false);

    $widgets = (fn (): array => $this->getHeaderWidgets())->call(app(EditPage::class));

    expect(array_values($widgets))->toBe([
        OverviewStats::class,
        SalesOverTimeChart::class,
        TopProductsTable::class,
        PaymentMethodMixChart::class,
    ]);

    /** Every one of them lazy, so four aggregate queries never hold up the form. */
    foreach ($widgets as $widget) {
        expect($widget::isLazy())->toBeTrue();
    }
});

it('hands each widget the record, so one Page\'s figures are drawn and another Page\'s are not', function () {
    [$reported, $other] = twoReportedPages();

    $symbol = app(GeneralSettings::class)->currencySymbol;

    /** Two Orders on this Page, 820.00 between them; the other Page's 960.00 is nowhere. */
    Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Orders')
        ->assertSee('Sales')
        ->assertSee('Average Order Value')
        ->assertSee('Cancellation Rate')
        ->assertSee('Unpaid')
        ->assertSee('New awaiting call')
        ->assertSee('Visits')
        ->assertSee('Not counted')
        ->assertSee($symbol.'820.00')
        ->assertSee($symbol.'410.00')
        ->assertDontSee($symbol.'960.00');

    Livewire::test(TopProductsTable::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Top Products')
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

it('offers the Reporting Window as a header action with the Dashboard\'s presets, and every widget obeys it', function () {
    [$reported] = twoReportedPages();

    $screen = Livewire::test(EditPage::class, ['record' => $reported->getKey()])
        ->assertActionExists('reportingWindow')
        ->mountAction('reportingWindow')
        ->assertMountedActionModalSee('Reporting Window')
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'last_7_days'])
        ->assertSchemaComponentHidden(ReportingWindow::FROM_FILTER)
        ->assertSchemaComponentHidden(ReportingWindow::UNTIL_FILTER)
        ->setActionData([ReportingWindow::PRESET_FILTER => 'custom'])
        ->assertSchemaComponentVisible(ReportingWindow::FROM_FILTER)
        ->assertSchemaComponentVisible(ReportingWindow::UNTIL_FILTER)
        ->setActionData([ReportingWindow::PRESET_FILTER => 'today'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    /**
     * The widgets are lazy, so the screen's own render carries only their
     * placeholders; what is pinned is the hand-over — the chosen window is
     * what every one of them is mounted with.
     */
    $page = $screen->instance();

    expect($page->filters[ReportingWindow::PRESET_FILTER])->toBe('today');

    $components = collect($page->getWidgetsSchemaComponents((fn (): array => $this->getHeaderWidgets())->call($page)));

    expect($components)->toHaveCount(4);

    $components->each(function ($component) use ($reported): void {
        expect($component->getData()['pageFilters'][ReportingWindow::PRESET_FILTER])->toBe('today')
            ->and($component->getData()['record']->getKey())->toBe($reported->getKey());
    });

    /** Today's window leaves the Order from three days ago out. */
    $symbol = app(GeneralSettings::class)->currencySymbol;

    Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => $page->filters])
        ->assertSee($symbol.'660.00')
        ->assertDontSee($symbol.'820.00');

    /** A fresh visit reads the session rather than the default. */
    Livewire::test(EditPage::class, ['record' => $reported->getKey()])
        ->mountAction('reportingWindow')
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'today']);
});

it('names the chosen window on the button, since the control itself is shut inside a modal', function () {
    [$reported, $other] = twoReportedPages();

    /** Before anything is picked, the button says what the widgets are counting. */
    $this->get(PageResource::getUrl('edit', ['record' => $reported]))
        ->assertOk()
        ->assertSee('Reporting Window')
        ->assertSee('Last 7 days');

    Livewire::test(EditPage::class, ['record' => $reported->getKey()])
        ->mountAction('reportingWindow')
        ->setActionData([
            ReportingWindow::PRESET_FILTER => 'custom',
            ReportingWindow::FROM_FILTER => '2026-09-01',
            ReportingWindow::UNTIL_FILTER => '2026-09-03',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    /**
     * A Custom window names its days rather than the word Custom, and the
     * window follows the Merchant onto the next Page they open — which is
     * the reason it has to be legible without opening the modal at all.
     */
    $this->get(PageResource::getUrl('edit', ['record' => $other]))
        ->assertOk()
        ->assertSee('1 Sep – 3 Sep 2026');
});

it('reads a draft Page that never took an Order as zeros and an empty chart, not as an error', function () {
    $draft = Page::factory()->create(['title' => 'Never sold']);

    Order::factory()->create(['subtotal' => 90000, 'delivery_charge' => 6000, 'created_at' => now()]);

    $this->get(PageResource::getUrl('edit', ['record' => $draft]))->assertOk();

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

it('still reports on a trashed Page, whose Orders were captured before it was retired', function () {
    [$reported] = twoReportedPages();
    $reported->delete();

    $symbol = app(GeneralSettings::class)->currencySymbol;

    $this->get(PageResource::getUrl('edit', ['record' => $reported]))
        ->assertOk()
        ->assertSeeLivewire(OverviewStats::class);

    Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee($symbol.'820.00')
        ->assertDontSee($symbol.'960.00');

    Livewire::test(TopProductsTable::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Sylhet tea')
        ->assertDontSee('Rangpur rug');
});
