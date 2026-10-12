<?php

use App\Enums\Permission;
use App\Filament\Clusters\Reports\Pages\BuyingReport;
use App\Filament\Clusters\Reports\Pages\CourierReport;
use App\Filament\Clusters\Reports\Pages\CustomerReport;
use App\Filament\Clusters\Reports\Pages\MarginReport;
use App\Filament\Clusters\Reports\Pages\StockReport;
use App\Filament\Clusters\Reports\Pages\VisitsReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CourierMoneyTable;
use App\Filament\Widgets\DeliveryMixTable;
use App\Filament\Widgets\MostVisitedTable;
use App\Filament\Widgets\NeedsAttentionTable;
use App\Filament\Widgets\OrderStatusBreakdownChart;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PaymentMethodMixChart;
use App\Filament\Widgets\SalesByCategoryTable;
use App\Filament\Widgets\SalesOverTimeChart;
use App\Filament\Widgets\TopCustomersTable;
use App\Filament\Widgets\TopProductsTable;
use App\Filament\Widgets\UnsentOrdersStat;
use App\Filament\Widgets\VisitsOverTimeChart;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * The Merchant's figures and the operator's to-do list stop being one screen
 * (#161). These assert what a User with, and without, `view reports` and
 * `work orders` reaches — the Reports cluster over HTTP, the sidebar, and the
 * Dashboard's own widget selection — never how the gate is wired.
 *
 * The Reports pages are gated at the cluster and the Dashboard's money widgets
 * are gated in the same breath, so a gate here is not one a Merchant walks
 * around one click away. The Dashboard page itself stays ungated: a User who
 * holds a Role reaches it, and meets whichever widgets are theirs.
 */
function everyReportPage(): array
{
    return [CourierReport::class, StockReport::class, BuyingReport::class, MarginReport::class, VisitsReport::class, CustomerReport::class];
}

/** The Merchant's figures, gated behind `view reports`. */
function dashboardMoneyWidgets(): array
{
    return [
        OverviewStats::class,
        SalesOverTimeChart::class,
        OrderStatusBreakdownChart::class,
        PaymentMethodMixChart::class,
        TopProductsTable::class,
        DeliveryMixTable::class,
        CourierMoneyTable::class,
        VisitsOverTimeChart::class,
        SalesByCategoryTable::class,
        TopCustomersTable::class,
        MostVisitedTable::class,
    ];
}

/** The operator's to-do list, gated behind `work orders`. */
function dashboardOperatorWidgets(): array
{
    return [NeedsAttentionTable::class, UnsentOrdersStat::class];
}

/**
 * The widget classes the Dashboard would draw for the current User — the
 * selection its own `getWidgets()` makes, which is where the gate lives.
 */
function dashboardWidgetsForCurrentUser(): array
{
    return array_values(app(Dashboard::class)->getWidgets());
}

function reportsIsInSidebar(): bool
{
    return collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->contains(fn ($item): bool => $item->getLabel() === 'Reports');
}

it('lets a User holding view reports open every report, keeps Reports in the sidebar, and draws the money widgets on the Dashboard', function () {
    $this->actingAs(User::factory()->viewingReports()->create());

    expect(ReportsCluster::canAccess())->toBeTrue();

    foreach (everyReportPage() as $page) {
        $this->get($page::getUrl())->assertOk();
    }

    expect(reportsIsInSidebar())->toBeTrue();

    $this->get(Dashboard::getUrl())->assertOk();

    $widgets = dashboardWidgetsForCurrentUser();

    foreach (dashboardMoneyWidgets() as $widget) {
        expect($widgets)->toContain($widget);
    }

    /** The to-do list is a different Permission, so it is not theirs. */
    foreach (dashboardOperatorWidgets() as $widget) {
        expect($widgets)->not->toContain($widget);
    }
});

/**
 * The subject holds `work orders`, which opens the panel and the operator's
 * to-do list but not the reading. That is what makes the refusals mean
 * something: a role-less User would be turned away at the panel floor and
 * would fail these whether or not Reports were gated, proving nothing about
 * `view reports`.
 */
it('refuses every report and hides Reports and the money widgets from a panel User without view reports', function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    expect(ReportsCluster::canAccess())->toBeFalse();

    foreach (everyReportPage() as $page) {
        $this->get($page::getUrl())->assertForbidden();
    }

    expect(reportsIsInSidebar())->toBeFalse();

    $widgets = dashboardWidgetsForCurrentUser();

    foreach (dashboardMoneyWidgets() as $widget) {
        expect($widgets)->not->toContain($widget);
    }
});

it('draws Needs Attention and Unsent Orders on the Dashboard for a User holding work orders', function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    $widgets = dashboardWidgetsForCurrentUser();

    expect($widgets)
        ->toContain(NeedsAttentionTable::class)
        ->toContain(UnsentOrdersStat::class);
});

/**
 * A plain Member holds a Role — so the panel floor lets them in — but neither
 * `view reports` nor `work orders`. They meet a Dashboard carrying none of the
 * gated widgets, and no error.
 */
it('leaves a User holding neither with a working Dashboard carrying none of the gated widgets', function () {
    $this->actingAs(User::factory()->create());

    $this->get(Dashboard::getUrl())->assertOk();

    $widgets = dashboardWidgetsForCurrentUser();

    foreach ([...dashboardMoneyWidgets(), ...dashboardOperatorWidgets()] as $widget) {
        expect($widgets)->not->toContain($widget);
    }
});

/**
 * The Merchant lands on the whole thing: holding both Permissions, every gated
 * widget is theirs on one screen.
 */
it('draws every gated widget on the Dashboard for a User holding both Permissions', function () {
    $this->actingAs(User::factory()->viewingReports()->workingOrders()->create());

    $widgets = dashboardWidgetsForCurrentUser();

    foreach ([...dashboardMoneyWidgets(), ...dashboardOperatorWidgets()] as $widget) {
        expect($widgets)->toContain($widget);
    }
});
