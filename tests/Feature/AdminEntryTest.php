<?php

use App\Filament\Pages\Dashboard;
use App\Models\User;

it('opens onto the dashboard', function () {
    $this->actingAs(User::factory()->create());

    /**
     * The Dashboard declares the panel root as its own route path, so the bare
     * URL renders it rather than redirecting anywhere. Asserted through the
     * Livewire component so a sidebar link bearing the same word cannot pass
     * for the page itself.
     */
    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class);
});

it('sends a signed-out User to the login screen', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});
