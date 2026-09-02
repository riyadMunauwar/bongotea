<?php

use App\Filament\Pages\Auth\Login;
use Filament\Support\Enums\Width;
use Livewire\Livewire;

/**
 * Same reason as `AdminEntryTest`: rendering an admin screen over HTTP leaves
 * Livewire's "a component rendered this request" flag set in static state, and
 * it would otherwise follow this file into the next one.
 */
afterEach(function (): void {
    Livewire::flushState();
});

it('serves the login page the panel owns rather than the framework one', function () {
    expect(filament()->getCurrentOrDefaultPanel()->getLoginRouteAction())->toBe(Login::class);
});

it('carries the brand mark above the form', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSeeInOrder(['gp-brand-mark', 'fi-simple-header-heading', 'fi-sc-form'], escape: false);
});

/**
 * The mark stands in for the wordmark rather than joining it, so the screen
 * does not stack two lines of bold branding above its own heading. Filament
 * prints the wordmark inside `.fi-logo`, and nothing else on this screen does.
 */
it('shows the mark in place of the wordmark', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('gp-brand-mark')
        ->assertDontSee('fi-logo');
});

/**
 * The card is the only object on the screen, so its width is a design value
 * rather than a default: read off the class the layout prints, because that is
 * what the browser actually sizes it by.
 */
it('draws the form in a card narrower than the framework default', function () {
    expect(filament()->getCurrentOrDefaultPanel()->getSimplePageMaxContentWidth())->toBe(Width::Medium);

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('fi-width-md');
});
