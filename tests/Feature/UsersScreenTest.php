<?php

use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * The Users screen, driven the way an Owner drives it.
 *
 * Owner-only, above the Settings cluster's own floor: `manage settings` opens
 * the cluster, but handing out access is how somebody would promote
 * themselves, so it is held back to the Owner exactly as the Roles screen is.
 */
it('opens for an Owner and forbids everyone else, manage settings included', function () {
    $this->actingAs(User::factory()->owner()->create());
    $this->get(UserResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->managingSettings()->create());
    expect(UserResource::canAccess())->toBeFalse();
    $this->get(UserResource::getUrl('index'))->assertForbidden();

    $this->actingAs(User::factory()->create());
    $this->get(UserResource::getUrl('index'))->assertForbidden();
});

it('leaves the Settings cluster as before for a manage settings User, with no Users entry', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(General::getUrl())
        ->assertOk()
        ->assertDontSee(UserResource::getUrl('index'));

    $this->actingAs(User::factory()->owner()->create());

    $this->get(General::getUrl())
        ->assertOk()
        ->assertSee(UserResource::getUrl('index'));
});

it('sits in the Settings cluster at navigation sort 8, above Roles', function () {
    expect(UserResource::getNavigationSort())->toBe(8)
        ->and(UserResource::getCluster())->toBe(SettingsCluster::class);
});

it('adds a User with a name, an email and an initial password they can sign in with', function () {
    $this->actingAs(User::factory()->owner()->create());
    $clerk = Role::findOrCreate('Orders Clerk');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Nadia',
            'email' => 'nadia@example.test',
            'password' => 'a-good-password',
            'roles' => [$clerk->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $nadia = User::query()->where('email', 'nadia@example.test')->sole();
    expect($nadia->name)->toBe('Nadia')
        ->and($nadia->hasRole('Orders Clerk'))->toBeTrue();

    // And that password is the one they now sign in with.
    auth()->logout();
    Livewire::test(Login::class)
        ->fillForm(['email' => 'nadia@example.test', 'password' => 'a-good-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->id())->toBe($nadia->getKey());
});

it('gives and takes Roles on a User', function () {
    $this->actingAs(User::factory()->owner()->create());
    $clerk = Role::findOrCreate('Orders Clerk');
    $counter = Role::findOrCreate('Shelf Counter');
    $subject = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $subject->getKey()])
        ->fillForm(['roles' => [$clerk->getKey(), $counter->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subject->fresh()->roles->pluck('name')->sort()->values()->all())
        ->toContain('Orders Clerk', 'Shelf Counter');

    Livewire::test(EditUser::class, ['record' => $subject->getKey()])
        ->fillForm(['roles' => [$clerk->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subject->fresh()->roles->pluck('name')->all())->toBe(['Orders Clerk']);
});

it('asks for confirmation when a save would empty a User down to no Roles', function () {
    $this->actingAs(User::factory()->owner()->create());
    $subject = User::factory()->create();
    $keep = $subject->roles->first();

    // With a Role kept, the save runs straight through.
    expect(Livewire::test(EditUser::class, ['record' => $subject->getKey()])
        ->set('data.roles', [$keep->getKey()])
        ->instance()->willSuspendOnSave())->toBeFalse();

    // Emptied, the save turns into a confirmation before it suspends.
    expect(Livewire::test(EditUser::class, ['record' => $subject->getKey()])
        ->set('data.roles', [])
        ->instance()->willSuspendOnSave())->toBeTrue();
});

it('suspends a User taken down to no Roles: no sign-in, still listed, back when a Role returns', function () {
    $this->actingAs($owner = User::factory()->owner()->create());
    $subject = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $subject->getKey()])
        ->set('data.roles', [])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subject->fresh()->roles)->toBeEmpty();

    // Cannot sign in.
    auth()->logout();
    Livewire::test(Login::class)
        ->fillForm(['email' => $subject->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    // Still in the list.
    $this->actingAs($owner);
    Livewire::test(ListUsers::class)->assertSee($subject->name);

    // Back the moment a Role is given.
    $clerk = Role::findOrCreate('Orders Clerk');
    Livewire::test(EditUser::class, ['record' => $subject->getKey()])
        ->fillForm(['roles' => [$clerk->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subject->fresh()->canAccessPanel(filament()->getCurrentOrDefaultPanel()))->toBeTrue();
});

it('files a User away: off the default list, cannot sign in, and can be brought back', function () {
    $this->actingAs($owner = User::factory()->owner()->create());
    $subject = User::factory()->create(['name' => 'Departed Dan']);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($subject));

    expect($subject->fresh()->trashed())->toBeTrue();

    // Cannot sign in — the soft-delete scope hides the row from the guard.
    auth()->logout();
    Livewire::test(Login::class)
        ->fillForm(['email' => $subject->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    // Off the default list.
    $this->actingAs($owner);
    Livewire::test(ListUsers::class)->assertDontSee('Departed Dan');

    // Brought back.
    Livewire::test(ListUsers::class)
        ->filterTable('trashed', ['value' => true])
        ->callAction(TestAction::make('restore')->table($subject));

    expect($subject->fresh()->trashed())->toBeFalse();
});

it('keeps a suspended or filed-away User on the Stock Counts and movements they made', function () {
    $suspended = User::factory()->suspended()->create(['name' => 'Suspended Sam']);
    $filed = User::factory()->create(['name' => 'Filed Faye']);

    $count = StockCount::factory()->create(['user_id' => $suspended->getKey()]);
    $movement = StockMovement::factory()->create(['user_id' => $filed->getKey()]);

    $filed->delete();

    expect($count->fresh()->user->name)->toBe('Suspended Sam')
        ->and($movement->fresh()->user->name)->toBe('Filed Faye');
});

it('refuses to demote, suspend or file away the last Owner, and says why', function () {
    $this->actingAs($owner = User::factory()->owner()->create());
    $clerk = Role::findOrCreate('Orders Clerk');

    // Demote (take the Owner Role, leave another): refused.
    Livewire::test(EditUser::class, ['record' => $owner->getKey()])
        ->fillForm(['roles' => [$clerk->getKey()]])
        ->call('save')
        ->assertNotified();

    expect($owner->fresh()->isOwner())->toBeTrue();

    // File away: refused.
    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($owner))
        ->assertNotified();

    expect($owner->fresh()->trashed())->toBeFalse();
});

it('refuses to take away your own last Role', function () {
    User::factory()->owner()->create();
    $second = User::factory()->owner()->create();
    $this->actingAs($second);

    // A second Owner stands, so the last-Owner guard does not fire; the
    // own-last-Role guard does. And because that save is going to be refused,
    // it is not dressed up as a suspension to confirm first.
    $page = Livewire::test(EditUser::class, ['record' => $second->getKey()])
        ->set('data.roles', []);

    expect($page->instance()->willSuspendOnSave())->toBeFalse();

    $page->call('save')->assertNotified();

    expect($second->fresh()->roles)->not->toBeEmpty();
});

it('lets an Owner change their own name and password from the profile page', function () {
    $this->actingAs($owner = User::factory()->owner()->create());

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Renamed Owner',
            'password' => 'a-brand-new-password',
            'passwordConfirmation' => 'a-brand-new-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $owner->refresh();
    expect($owner->name)->toBe('Renamed Owner')
        ->and(Hash::check('a-brand-new-password', $owner->password))->toBeTrue();
});

it('shows no email field on the profile page, so a login is not renamed there', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(EditProfile::class)
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('password')
        ->assertFormFieldDoesNotExist('email');
});
