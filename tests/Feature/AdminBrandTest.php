<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('names the panel GioPages rather than the framework default', function () {
    expect(config('app.name'))->toBe('GioPages')
        ->and(filament()->getCurrentOrDefaultPanel()->getBrandName())->toBe('GioPages');
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
