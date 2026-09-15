<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The Role every User made here holds, before any state adds another.
     *
     * It carries no Permission at all, which is the point twice over: holding
     * a Role is the floor for the panel, so a plain User can open the admin
     * the way an ordinary one does; and holding no Permission means every
     * gate is still asserted honestly, so a test that opens a gated screen
     * with a plain User is telling the truth about the gate.
     */
    private const BASE_ROLE = 'Member';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A User is somebody with a job here, so every one made here holds a Role.
     *
     * A User holding none is Suspended — refused at the panel door rather than
     * shown an empty admin — which is a state to ask for deliberately, not the
     * one a factory hands out by default. A state naming a job-shaped Role
     * adds it alongside this one, since a User may hold any number and what
     * they may do is the union; `suspended()` takes them all away again.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(Role::findOrCreate(self::BASE_ROLE)));
    }

    /**
     * The User who may do everything, by standing outside the Permissions
     * rather than holding them (ADR-0024). Every test of what an Owner may
     * reach goes through a User made this way and asks `can()`, never the
     * pivot tables, which answer no for an Owner and are the wrong question.
     */
    public function owner(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(Role::owner()));
    }

    /**
     * A User who may open Settings: one holding a Role that carries the
     * Permission the cluster is gated by. Authority comes through a Role and
     * no other way (ADR-0025) — a factory state calling `givePermissionTo()`
     * on a User is a sign that decision has been broken.
     */
    public function managingSettings(): static
    {
        return $this->holdingRole('Settings Manager', Permission::ManageSettings);
    }

    /**
     * A User holding no Role at all, which is what Suspended is: they keep
     * their name on everything they did and lose every way in.
     */
    public function suspended(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([]));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A User holding a named Role carrying exactly the Permissions given.
     *
     * The rows are made here rather than assumed, because the catalogue is the
     * enum and the table is only where it is written down (ADR-0026): a test
     * must not depend on `permission:sync` having been run.
     */
    private function holdingRole(string $name, Permission ...$permissions): static
    {
        return $this->afterCreating(function (User $user) use ($name, $permissions): void {
            $catalogue = app(PermissionCatalogue::class);

            $role = Role::findOrCreate($name);
            $role->syncPermissions(array_map($catalogue->ensure(...), $permissions));

            $user->assignRole($role);
        });
    }
}
