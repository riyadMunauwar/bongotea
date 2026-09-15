<?php

use App\Filament\Clusters\Settings\Pages\General;
use App\Models\Role;
use App\Models\User;

/**
 * The one route into the panel that does not go through the panel (ADR-0024):
 * how a fresh installation gets its first Owner, and the whole of the answer
 * to "the only Owner has left the company".
 */
it('makes the User at that address an Owner', function () {
    $user = User::factory()->create(['name' => 'Rumi', 'email' => 'rumi@example.com']);

    $this->artisan('giopages:owner', ['email' => 'rumi@example.com'])
        ->expectsOutputToContain('Rumi (rumi@example.com) is now an Owner.')
        ->assertSuccessful();

    expect($user->fresh()->isOwner())->toBeTrue();

    $this->actingAs($user->fresh())->get(General::getUrl())->assertOk();
});

/**
 * An installation nobody can sign into is exactly what this command is for,
 * so it does not need a Role to have been handed out first.
 */
it('brings a suspended User back as an Owner', function () {
    $user = User::factory()->suspended()->create(['email' => 'rumi@example.com']);

    $this->artisan('giopages:owner', ['email' => 'rumi@example.com'])->assertSuccessful();

    $this->actingAs($user->fresh())->get('/admin')->assertOk();
});

it('says so when the address matches nobody, and fails', function () {
    $this->artisan('giopages:owner', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No User is registered under nobody@example.com.')
        ->assertFailed();

    expect(Role::query()->where('name', Role::OWNER)->exists())->toBeFalse();
});

it('is safe to run twice', function () {
    $user = User::factory()->create(['name' => 'Rumi', 'email' => 'rumi@example.com']);

    $this->artisan('giopages:owner', ['email' => 'rumi@example.com'])->assertSuccessful();

    $this->artisan('giopages:owner', ['email' => 'rumi@example.com'])
        ->expectsOutputToContain('Rumi (rumi@example.com) is already an Owner.')
        ->assertSuccessful();

    expect($user->fresh()->roles()->where('name', Role::OWNER)->count())->toBe(1);
});

/**
 * A second Owner is an ordinary thing — an installation is never left with
 * none, so somebody handing over makes their replacement one first.
 */
it('makes an Owner beside an existing one', function () {
    User::factory()->owner()->create();
    $second = User::factory()->create(['email' => 'second@example.com']);

    $this->artisan('giopages:owner', ['email' => 'second@example.com'])->assertSuccessful();

    expect($second->fresh()->isOwner())->toBeTrue()
        ->and(User::query()->get()->filter->isOwner())->toHaveCount(2);
});

it('leaves the Owner Role carrying no Permission rows', function () {
    User::factory()->create(['email' => 'rumi@example.com']);

    $this->artisan('giopages:owner', ['email' => 'rumi@example.com'])->assertSuccessful();

    expect(Role::owner()->permissions)->toBeEmpty();
});
