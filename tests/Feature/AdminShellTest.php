<?php

use App\Filament\Resources\Orders\OrderResource;
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
