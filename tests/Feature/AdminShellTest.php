<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Pages\PageResource;
use App\Models\User;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Enums\GlobalSearchPosition;
use Filament\Enums\UserMenuPosition;

/**
 * These shell assertions are made on the Orders index, which now sits behind
 * `work orders` (see `OrderResource::canAccess()`), so the User holding this
 * screen open holds that Permission.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->workingOrders()->create());
});

it('renders the panel without a topbar', function () {
    $this->get(OrderResource::getUrl('index'))
        ->assertOk()
        ->assertDontSee('fi-topbar')
        ->assertDontSee('fi-body-has-topbar');
});

/**
 * The topbar carried these three, so dropping it is only free as long as
 * Filament has moved them into the sidebar rather than dropped them.
 */
it('keeps global search and the account menu, in the sidebar', function () {
    $panel = filament()->getCurrentOrDefaultPanel();

    expect($panel->getGlobalSearchPosition())->toBe(GlobalSearchPosition::Sidebar)
        ->and($panel->getUserMenuPosition())->toBe(UserMenuPosition::Sidebar)
        ->and($panel->getDatabaseNotificationsPosition())->toBe(DatabaseNotificationsPosition::Sidebar);

    $this->get(OrderResource::getUrl('index'))
        ->assertOk()
        ->assertSee('fi-sidebar-footer')
        ->assertSee('fi-user-menu');
});

/**
 * Both widths are read by the theme's CSS off the custom properties the panel
 * prints into the document, so the page itself is where they are worth
 * asserting.
 */
it('gives the sidebar 14rem, collapsing to a 3.5rem rail', function () {
    $this->get(OrderResource::getUrl('index'))
        ->assertOk()
        ->assertSee('--sidebar-width: 14rem', escape: false)
        ->assertSee('--collapsed-sidebar-width: 3.5rem', escape: false)
        ->assertSee('fi-body-has-sidebar-collapsible-on-desktop');
});

/**
 * The sidebar order is stated, not inherited.
 *
 * Every top-level entry declares its own sort, so this is the assertion that
 * a resource generated later — which declares none, lands at Filament's `-1`
 * and jumps above the Dashboard — is caught here rather than by a Merchant.
 *
 * The User is granted `work orders` because `OrderResource::canAccess()` and
 * `ShipmentResource::canAccess()` otherwise hide Orders and Shipments,
 * `manage settings` because `SettingsCluster::canAccess()` otherwise hides the
 * last entry, `manage catalogue` because `ProductResource::canAccess()`
 * otherwise hides Products, `author pages` because `PageResource::canAccess()`
 * otherwise hides Pages, `handle enquiries` because
 * `EnquiryResource::canAccess()` otherwise hides Enquiries, `count stock` because
 * `InventoryCluster::canAccess()` otherwise hides Inventory, and `view reports`
 * because `ReportsCluster::canAccess()` otherwise hides Reports; between them
 * they pin the gated entries for someone who can see them. As each working area
 * gained its gate its Permission joined this list, and with Reports gated in
 * #161 that is now every top-level entry bar the Dashboard, which stays ungated
 * so anyone who can reach the panel lands somewhere.
 *
 * A cluster contributes one entry under its own label and never the pages or
 * resources inside it, so neither Courier Report nor Stock Counts is in this
 * list; `CourierReportScreenTest` and `StockScreensTest` are what hold those
 * clusters to what they draw.
 */
it('draws the sidebar in the order the panel states', function () {
    $this->actingAs(User::factory()->workingOrders()->managingSettings()->managingCatalogue()->authoringPages()->handlingEnquiries()->countingStock()->viewingReports()->create());

    $labels = collect(filament()->getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel())
        ->all();

    expect($labels)->toBe(['Dashboard', 'Orders', 'Products', 'Pages', 'Enquiries', 'Inventory', 'Reports', 'Settings']);
});

/**
 * Orders is the whole of working Orders: the queue, then the parcels that
 * became of them, then the people who placed them.
 */
it('draws shipments and customers under orders', function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    $orders = collect(filament()->getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->first(fn ($item): bool => $item->getLabel() === 'Orders');

    expect(collect($orders->getChildItems())->map(fn ($item) => $item->getLabel())->values()->all())
        ->toBe(['Shipments', 'Customers']);
});

/**
 * Pages is drawn with an icon this application owns rather than one Heroicons
 * ships, so its registration is a thing that can be absent.
 *
 * A missing file, a mistyped set name or a registration that never ran makes
 * `svg()` throw on every admin screen — which the suite would report as forty
 * unrelated failures. Named here, it reports as one.
 */
it('resolves the custom funnel the Pages icon names', function () {
    $icon = PageResource::getNavigationIcon();

    expect($icon)->toBeString()
        ->and(svg($icon)->toHtml())->toContain('<svg');
});
