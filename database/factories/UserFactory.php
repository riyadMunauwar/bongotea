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
     * A User who works Orders: one holding a Role that carries `work orders`,
     * the floor the Orders and Shipments screens and the everyday sending verbs
     * sit behind. Enough to open the queue, an Order and the Shipments list,
     * send a parcel, send a batch and check with the gateway — but none of the
     * acts somebody would regret, which each sit behind a Permission of their
     * own on top of this floor.
     */
    public function workingOrders(): static
    {
        return $this->holdingRole('Orders Clerk', Permission::WorkOrders);
    }

    /**
     * A User who may also write a parcel off: they work Orders and hold
     * `abandon parcels` on top, since abandoning is offered from an Order screen
     * only a User who works Orders can reach. What they may do is the union of
     * both, carried on one Role here (ADR-0025).
     */
    public function abandoningParcels(): static
    {
        return $this->holdingRole('Parcel Abandoner', Permission::WorkOrders, Permission::AbandonParcels);
    }

    /**
     * A User who may also say what came back from a half-delivered parcel: they
     * work Orders and hold `answer partly delivered` on top, the one answer the
     * stock ledger is waiting on.
     */
    public function answeringPartlyDelivered(): static
    {
        return $this->holdingRole('Returns Clerk', Permission::WorkOrders, Permission::AnswerPartlyDelivered);
    }

    /**
     * A User who may also switch a live Order to Cash on Delivery: they work
     * Orders and hold `switch to cash on delivery` on top, changing how money
     * is collected rather than an everyday verb.
     */
    public function switchingToCashOnDelivery(): static
    {
        return $this->holdingRole('Cash Switcher', Permission::WorkOrders, Permission::SwitchToCashOnDelivery);
    }

    /**
     * A User who may also delete, force-delete and restore an Order: they work
     * Orders and hold `delete orders` on top, so the queue cannot be tidied away
     * by whoever is merely working it.
     */
    public function deletingOrders(): static
    {
        return $this->holdingRole('Order Remover', Permission::WorkOrders, Permission::DeleteOrders);
    }

    /**
     * A User who may open the Products catalogue: one holding a Role that
     * carries the Permission the resource is gated by. As with every state
     * here, authority arrives through a Role, never a direct grant (ADR-0025).
     */
    public function managingProducts(): static
    {
        return $this->holdingRole('Products Manager', Permission::ManageProducts);
    }

    /**
     * A User who counts shelves: one holding a Role that carries the
     * Permission the Inventory cluster is gated by. Enough to open Stock
     * Counts, Suppliers and Supplies, take a Count and record a Supply — but
     * not to void a delivery, which is held back behind `void supplies`.
     */
    public function countingStock(): static
    {
        return $this->holdingRole('Stock Counter', Permission::CountStock);
    }

    /**
     * A User who may also strike a delivery out: they count stock and hold
     * `void supplies` on top, since voiding is offered from a Supply screen
     * only a User who counts can reach. What they may do is the union of both,
     * carried on one Role here (ADR-0025).
     */
    public function voidingSupplies(): static
    {
        return $this->holdingRole('Supply Voider', Permission::CountStock, Permission::VoidSupplies);
    }

    /**
     * A User who authors Pages: one holding a Role that carries the Permission
     * the Pages resource is gated by. Enough to open the list, write a Page,
     * publish it and pull it back to draft — but not to delete one, which is
     * held back behind `delete pages`.
     */
    public function authoringPages(): static
    {
        return $this->holdingRole('Page Author', Permission::AuthorPages);
    }

    /**
     * A User who may also delete a Page: they author Pages and hold
     * `delete pages` on top, since deleting is offered from the Pages screens
     * only a User who authors can reach. What they may do is the
     * union of both, carried on one Role here (ADR-0025).
     */
    public function deletingPages(): static
    {
        return $this->holdingRole('Page Deleter', Permission::AuthorPages, Permission::DeletePages);
    }

    /**
     * A User who reads the figures: one holding a Role that carries
     * `view reports`, the floor the Reports cluster and the Dashboard's own
     * money widgets both sit behind (#161). Enough to open every report and
     * to meet Sales, Average Order Value and the rest on the Dashboard — but
     * none of the operator's to-do list, which sits behind `work orders`.
     */
    public function viewingReports(): static
    {
        return $this->holdingRole('Reports Reader', Permission::ViewReports);
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
