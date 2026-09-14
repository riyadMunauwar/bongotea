<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Pages\PageResource;
use App\Models\User;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Enums\GlobalSearchPosition;
use Filament\Enums\UserMenuPosition;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
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
 * The User is granted `manage settings` because `SettingsCluster::canAccess()`
 * otherwise hides the last entry, so this pins Settings as last for someone
 * who can see it as well as pinning the six above it. Reports is ungated and
 * is drawn for anybody.
 *
 * A cluster contributes one entry under its own label and never its pages, so
 * Courier Report is not in this list; `CourierReportScreenTest` is what holds
 * the cluster to what it draws.
 */
it('draws the sidebar in the order the panel states', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $labels = collect(filament()->getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel())
        ->all();

    expect($labels)->toBe(['Dashboard', 'Orders', 'Shipments', 'Products', 'Pages', 'Reports', 'Settings']);
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
