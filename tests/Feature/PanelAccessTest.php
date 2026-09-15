<?php

use App\Enums\Permission;
use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\AuthenticateAdmin;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Who reaches the admin, and what they may do once they are in.
 *
 * Asserted at the seam a Merchant would notice it at — a screen is reachable
 * or it is not — rather than against the hook, the pivot or the cached map
 * that happen to arrange it today.
 */
it('lets a User holding a Role into the panel', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertOk();
});

it('refuses a User holding no Role, and tells them why', function () {
    $this->actingAs(User::factory()->suspended()->create());

    $response = $this->get('/admin');

    $response->assertForbidden();

    expect($response->exception?->getMessage())->toBe(AuthenticateAdmin::REFUSAL);
});

/**
 * Suspension is total, not a smaller admin: the Settings cluster's own gate
 * would refuse this User anyway, and the point is that the door does first.
 */
it('refuses a suspended User every screen, not only the gated ones', function () {
    $this->actingAs(User::factory()->suspended()->create());

    $this->get(General::getUrl())->assertForbidden();
    $this->get(SettingsCluster::getUrl())->assertForbidden();
});

it('will not sign a User with no Role in at all', function () {
    $suspended = User::factory()->suspended()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $suspended->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth()->check())->toBeFalse();
});

/**
 * Holding a Role is the floor and nothing more. Everything finer is a
 * Permission, so a Role carrying none opens the admin and no gate inside it.
 */
it('opens nothing gated for a User whose Role carries no Permission', function () {
    $this->actingAs(User::factory()->create());

    $this->get(General::getUrl())->assertForbidden();
    $this->get(SettingsCluster::getUrl())->assertForbidden();
});

it('opens Settings for a User whose Role carries the Permission', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(General::getUrl())->assertOk();

    /** The cluster's own URL hands off to the first page inside it. */
    $this->get(SettingsCluster::getUrl())->assertRedirect(General::getUrl());
});

it('gives a User everything their Roles allow between them', function () {
    $user = User::factory()->managingSettings()->create();

    $counting = Role::findOrCreate('Shelf Counter');
    $counting->givePermissionTo(app(PermissionCatalogue::class)->ensure(Permission::CountStock));

    $user->assignRole($counting);

    expect($user->can(Permission::CountStock))->toBeTrue()
        ->and($user->can(Permission::ManageSettings))->toBeTrue()
        ->and($user->can(Permission::WorkOrders))->toBeFalse();

    $this->actingAs($user)->get(General::getUrl())->assertOk();
});

/**
 * ADR-0025: authority travels down one channel, so what somebody may do has
 * exactly one explanation. A direct grant is not a supported way to give it,
 * and nothing in the application writes the pivot that would hold one.
 */
it('never grants a Permission straight to a User', function () {
    User::factory()->create();
    User::factory()->managingSettings()->create();
    User::factory()->owner()->create();

    expect(DB::table('model_has_permissions')->count())->toBe(0);
});

/**
 * Written against a User who is through the door on a Role of their own, so
 * the refusal below is the gate's and not the panel floor's: a grant sitting
 * in the pivot — the one the `manage settings` migration left behind — is not
 * a second, invisible way to hold a Permission.
 */
it('does not let a direct grant open a screen a Role would', function () {
    $user = User::factory()->create();

    $user->givePermissionTo(app(PermissionCatalogue::class)->ensure(Permission::ManageSettings));

    expect($user->fresh()->can(Permission::ManageSettings))->toBeFalse();

    $this->actingAs($user->fresh())->get(General::getUrl())->assertForbidden();
});

/**
 * ADR-0024: the Owner stands outside the Permissions rather than holding
 * every one, so the check that matters most is for a Permission this
 * installation has never heard of — what one shipped in a later release looks
 * like on the day before it is synced.
 */
it('passes an Owner every check, including for a Permission with no row', function () {
    $owner = User::factory()->owner()->create();

    expect($owner->can(Permission::ManageSettings))->toBeTrue()
        ->and($owner->can(Permission::AbandonParcels))->toBeTrue()
        ->and($owner->can('abandon the whole shop'))->toBeTrue();

    $this->actingAs($owner)->get(General::getUrl())->assertOk();
});

it('leaves the Owner Role holding no Permission of its own', function () {
    User::factory()->owner()->create();

    expect(Role::owner()->permissions)->toBeEmpty();
});

it('answers no for anybody who is not an Owner', function () {
    $user = User::factory()->create();

    expect($user->can('abandon the whole shop'))->toBeFalse()
        ->and($user->isOwner())->toBeFalse();
});
