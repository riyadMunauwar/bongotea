<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Models\User;

/** The brand assertions render the Orders index, which now sits behind `work orders`. */
beforeEach(function (): void {
    $this->actingAs(User::factory()->workingOrders()->create());
});

it('names the panel GioPages rather than the framework default', function () {
    expect(filament()->getCurrentOrDefaultPanel()->getBrandName())->toBe('GioPages');
});

/**
 * `.env` is untracked, so the example is the half of the name the repository
 * can hold itself to — and it is the half a fresh checkout and CI copy from.
 * The name above is only ever as right as this file is.
 */
it('carries the name in the environment example', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain('APP_NAME=GioPages');
});

it('shows the mark and the wordmark together in the sidebar', function () {
    $this->get(OrderResource::getUrl('index'))
        ->assertOk()
        ->assertSee('gp-brand-mark')
        ->assertSee('GioPages');
});

/**
 * The point of the whole ticket. Filament hides `fi-sidebar-header-logo-ctn`
 * the moment the sidebar collapses, so anything rendered inside it is gone in
 * the rail. Asserting the mark comes *before* that container is what pins it
 * outside — the wordmark is what may go with the width.
 */
it('renders the mark outside the container Filament hides in the rail', function () {
    $this->get(OrderResource::getUrl('index'))
        ->assertOk()
        ->assertSeeInOrder(['gp-brand-mark', 'fi-sidebar-header-logo-ctn'], escape: false);
});
