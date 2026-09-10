<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CachePathIndicator;
use App\Filament\Widgets\OverviewStats;
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
        ->assertSee('Not yet counted')
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

it('keeps the Cache Path indicator on the Dashboard, below the reports', function () {
    Page::factory()->published()->create();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(CachePathIndicator::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(OverviewStats::class, $widgets, true))
        ->toBeLessThan(array_search(CachePathIndicator::class, $widgets, true));
});
