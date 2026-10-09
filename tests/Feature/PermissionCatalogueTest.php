<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionRow;

/**
 * The catalogue is the enum and the table is where it is written down
 * (ADR-0026), so what is worth asserting here is the enum's own shape and
 * that the sync moves in one direction: the enum says, the table follows.
 */
it('names every Permission the product has, once each', function () {
    expect(Permission::cases())->toHaveCount(13)
        ->and(Permission::names())->toBe(array_unique(Permission::names()));
});

/**
 * The style is `manage settings`, the first Permission this system had, and
 * every one since follows it: lowercase, verb first, words separated by
 * spaces. A name is stored in a row and asked of `can()`, so restyling one is
 * a breaking change rather than a tidy-up.
 */
it('spells every name lowercase and verb first, under a TitleCase key', function (Permission $permission) {
    expect($permission->value)->toMatch('/^[a-z]+(?: [a-z]+)+$/')
        ->and($permission->name)->toMatch('/^(?:[A-Z][a-z]+)+$/');
})->with(Permission::cases());

it('gives every Permission a label and one of the two headings', function (Permission $permission) {
    expect($permission->getLabel())->not->toBe('')
        ->and($permission->group())->toBeIn([Permission::WORKING_AREAS, Permission::IRREVERSIBLE_ACTS]);
})->with(Permission::cases());

it('splits the catalogue into six working areas and seven acts that cannot be undone', function () {
    $grouped = collect(Permission::cases())->groupBy(fn (Permission $permission) => $permission->group());

    expect($grouped[Permission::WORKING_AREAS])->toHaveCount(6)
        ->and($grouped[Permission::IRREVERSIBLE_ACTS])->toHaveCount(7);
});

it('writes a row for every case the installation has not got', function () {
    PermissionRow::query()->delete();

    $this->artisan('permission:sync')->assertSuccessful();

    expect(PermissionRow::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::names())->sort()->values()->all());
});

it('prunes a row no case names, taking it off the Roles that held it', function () {
    $stray = PermissionRow::create(['name' => 'rearrange the furniture', 'guard_name' => 'web']);

    $role = Role::findOrCreate('Caretaker');
    $role->givePermissionTo($stray);

    $this->artisan('permission:sync')
        ->expectsOutputToContain('Removed 1 Permission. rearrange the furniture')
        ->assertSuccessful();

    expect(PermissionRow::query()->where('name', 'rearrange the furniture')->exists())->toBeFalse()
        ->and($role->fresh()->permissions)->toBeEmpty();
});

it('reports what it did and leaves a synced installation alone', function () {
    $this->artisan('permission:sync')->assertSuccessful();

    $this->artisan('permission:sync')
        ->expectsOutputToContain('Registered 0 Permissions.')
        ->expectsOutputToContain('Removed 0 Permissions.')
        ->assertSuccessful();

    expect(PermissionRow::query()->count())->toBe(count(Permission::cases()));
});

/**
 * The cached map is what a revoked Permission keeps working through, so
 * forgetting it is part of the sync rather than something to run after it.
 */
it('takes a Permission away from a Role the moment the sync removes it', function () {
    $stray = PermissionRow::create(['name' => 'rearrange the furniture', 'guard_name' => 'web']);

    $role = Role::findOrCreate('Caretaker');
    $role->givePermissionTo($stray);

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('rearrange the furniture'))->toBeTrue();

    $this->artisan('permission:sync')->assertSuccessful();

    expect($user->fresh()->can('rearrange the furniture'))->toBeFalse();
});

/**
 * Nothing is incorrect on an installation that never ran the command: a Role
 * can be given a Permission whose row does not exist yet, which is what keeps
 * an upgrade from hiding a new screen behind a gate nobody can open.
 */
it('grants a Permission that was never synced', function () {
    PermissionRow::query()->delete();

    $role = Role::findOrCreate('Parcel Writer-Off');
    $role->givePermissionTo(app(PermissionCatalogue::class)->ensure(Permission::AbandonParcels));

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can(Permission::AbandonParcels))->toBeTrue();
});

/**
 * A seeded installation has to be one somebody can open, which a role-less
 * User is not: the seeder mints an Owner and registers the catalogue, the
 * development counterpart of `giopages:owner` on a real installation.
 */
it('seeds the catalogue and an Owner who can reach the admin', function () {
    PermissionRow::query()->delete();

    $this->seed();

    $owner = User::query()->where('email', 'test@example.com')->sole();

    expect($owner->isOwner())->toBeTrue()
        ->and(PermissionRow::query()->count())->toBe(count(Permission::cases()));

    $this->actingAs($owner)->get('/admin')->assertOk();
});

it('keeps the catalogue out of the direct-grant pivot entirely', function () {
    $this->artisan('permission:sync')->assertSuccessful();

    expect(DB::table('model_has_permissions')->count())->toBe(0);
});
