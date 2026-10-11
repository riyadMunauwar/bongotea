<?php

use App\Enums\Permission;
use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionRow;

/**
 * The Roles screen, driven the way a Merchant drives it.
 *
 * A resource inside the Settings cluster that sits above the cluster's floor:
 * `manage settings` opens the cluster, but composing Roles is Owner-only,
 * because it is how somebody would promote themselves. The ticks come from
 * the catalogue enum, not the `permissions` table, so a Permission shipped in
 * a release the installation has not synced is still tickable (ADR-0026).
 */
it('opens for an Owner and forbids everyone else, manage settings included', function () {
    $this->actingAs(User::factory()->owner()->create());
    $this->get(RoleResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->managingSettings()->create());
    expect(RoleResource::canAccess())->toBeFalse();
    $this->get(RoleResource::getUrl('index'))->assertForbidden();

    $this->actingAs(User::factory()->create());
    $this->get(RoleResource::getUrl('index'))->assertForbidden();
});

/**
 * The cluster is unchanged for a User who holds only `manage settings`: every
 * Settings page opens as before and the sidebar carries no Roles link, while
 * an Owner sees the link in the same place.
 */
it('leaves the Settings cluster as before for a manage settings User, with no Roles entry', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(General::getUrl())
        ->assertOk()
        ->assertDontSee(RoleResource::getUrl('index'));

    $this->get(SettingsCluster::getUrl())->assertRedirect(General::getUrl());

    $this->actingAs(User::factory()->owner()->create());

    $this->get(General::getUrl())
        ->assertOk()
        ->assertSee(RoleResource::getUrl('index'));
});

it('sits last in the Settings cluster, at navigation sort 11', function () {
    expect(RoleResource::getNavigationSort())->toBe(11)
        ->and(RoleResource::getCluster())->toBe(SettingsCluster::class);
});

it('draws the ticks grouped under their two headings, from the catalogue', function () {
    $this->actingAs(User::factory()->owner()->create());

    Livewire::test(CreateRole::class)
        ->assertSee(Permission::WORKING_AREAS)
        ->assertSee(Permission::IRREVERSIBLE_ACTS)
        ->assertSee(Permission::WorkOrders->getLabel())
        ->assertSee(Permission::AbandonParcels->getLabel());
});

it('composes a Role from ticks and gives a holder exactly those Permissions', function () {
    $this->actingAs(User::factory()->owner()->create());

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'Orders Clerk',
            'working_areas' => [Permission::WorkOrders->value],
            'irreversible_acts' => [Permission::AbandonParcels->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'Orders Clerk')->sole();

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe([Permission::AbandonParcels->value, Permission::WorkOrders->value]);

    $holder = User::factory()->create();
    $holder->assignRole($role);

    expect($holder->can(Permission::WorkOrders))->toBeTrue()
        ->and($holder->can(Permission::AbandonParcels))->toBeTrue()
        ->and($holder->can(Permission::CountStock))->toBeFalse();
});

/**
 * ADR-0026: the ticks are the enum and the table only follows it, so a
 * Permission with no row can still be ticked and the save makes the row —
 * which is what keeps an upgrade from hiding a screen behind a gate nobody
 * can grant.
 */
it('grants a Permission that has no row yet, creating the row on save', function () {
    $this->actingAs(User::factory()->owner()->create());

    PermissionRow::query()->delete();

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'Parcel Writer-Off',
            'irreversible_acts' => [Permission::AbandonParcels->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'Parcel Writer-Off')->sole();

    expect($role->permissions->pluck('name'))->toContain(Permission::AbandonParcels->value)
        ->and(PermissionRow::query()->where('name', Permission::AbandonParcels->value)->exists())->toBeTrue();
});

/**
 * The Owner is listed but locked: its name cannot be changed and it draws no
 * ticks, because it holds no Permission rows and unticked boxes would lie
 * about what it may do (ADR-0024).
 */
it('locks the Owner Role: name disabled, no ticks, a note instead', function () {
    $this->actingAs(User::factory()->owner()->create());

    Livewire::test(EditRole::class, ['record' => Role::owner()->getKey()])
        ->assertFormFieldDisabled('name')
        ->assertDontSee(Permission::WorkOrders->getLabel())
        ->assertSee('holds no individual Permissions');
});

it('hides the delete action on the Owner Role', function () {
    $this->actingAs(User::factory()->owner()->create());

    Livewire::test(ListRoles::class)
        ->assertActionHidden(TestAction::make('delete')->table(Role::owner()));
});

it('refuses to rename or re-permission the Owner through a crafted save', function () {
    $this->actingAs(User::factory()->owner()->create());
    $owner = Role::owner();

    Livewire::test(EditRole::class, ['record' => $owner->getKey()])
        ->fillForm(['name' => 'Overlord'])
        ->call('save');

    expect($owner->fresh()->name)->toBe(Role::OWNER)
        ->and($owner->fresh()->permissions)->toBeEmpty();
});

it('shows how many Users hold each Role', function () {
    $this->actingAs(User::factory()->owner()->create());

    $role = Role::findOrCreate('Orders Clerk');
    User::factory()->count(2)->create()->each->assignRole($role);

    Livewire::test(ListRoles::class)
        ->assertSee('Users')
        ->assertSee('Orders Clerk');

    expect($role->loadCount('users')->users_count)->toBe(2);
});

/**
 * One click never revokes several people's access at once: a delete is
 * refused while anybody holds the Role, and the refusal says how many do.
 */
it('refuses to delete a Role a User still holds, and says how many', function () {
    $this->actingAs(User::factory()->owner()->create());

    $held = Role::findOrCreate('Orders Clerk');
    User::factory()->count(2)->create()->each->assignRole($held);

    Livewire::test(ListRoles::class)
        ->callAction(TestAction::make('delete')->table($held))
        ->assertNotified();

    expect(Role::query()->whereKey($held->getKey())->exists())->toBeTrue();
});

it('deletes a Role nobody holds', function () {
    $this->actingAs(User::factory()->owner()->create());

    $unheld = Role::findOrCreate('Nobody Holds This');

    Livewire::test(ListRoles::class)
        ->callAction(TestAction::make('delete')->table($unheld));

    expect(Role::query()->whereKey($unheld->getKey())->exists())->toBeFalse();
});

/**
 * ADR-0025: authority runs down Roles alone and the cached map is dropped on
 * the write, so unticking a Permission stops working on the holder's next
 * request rather than lingering until the cache expires.
 */
it('drops a Permission on the next request when it is unticked', function () {
    $this->actingAs(User::factory()->owner()->create());

    $role = Role::findOrCreate('Settings Deputy');
    $role->syncPermissions(app(PermissionCatalogue::class)->ensure(Permission::ManageSettings));

    $holder = User::factory()->create();
    $holder->assignRole($role);

    expect($holder->can(Permission::ManageSettings))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['working_areas' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($holder->fresh()->can(Permission::ManageSettings))->toBeFalse();
});
