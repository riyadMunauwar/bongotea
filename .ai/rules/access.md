---
paths:
  - app/Enums/Permission.php
  - app/Models/Role.php
  - app/Models/User.php
  - 'app/Support/Access/**'
  - app/Http/Middleware/AuthenticateAdmin.php
  - app/Console/Commands/SyncPermissionsCommand.php
  - app/Console/Commands/GrantOwnerCommand.php
  - database/factories/UserFactory.php
  - 'app/Filament/Resources/Users/**'
  - app/Filament/Pages/Auth/EditProfile.php
---

# Access

## Access: the enum is the catalogue, a Role is the only channel, the Owner bypasses
`App\Enums\Permission` is the single declaration site for every Permission (ADR-0026). A gate names a typed case — never a string literal, never a migration per Permission. The `permissions` table is storage the enum is written into: `PermissionCatalogue::ensure()` creates a row when something first grants it, `permission:sync` pre-creates and prunes, and nothing is incorrect on an installation that never ran it. Never build a list of Permissions by reading the table.

Authority comes from Roles and nothing else (ADR-0025). `User::hasDirectPermission()` is hard-coded false, so a row in `model_has_permissions` — the old `manage settings` migration left some — decides nothing, and nothing writes that pivot. An exception for one person is a new Role, not a tick. Every write touching Roles or their Permissions ends in `PermissionCatalogue::forget()`.

The Owner is a Role by name (`Role::OWNER`, made through `Role::owner()`) holding no Permission rows; a `Gate::before` hook in `AppServiceProvider::boot()` answers true for it before the map is consulted (ADR-0024), so a Permission with no row is still theirs. Never ask the pivot or `hasPermissionTo()` what an Owner may do — ask `can()`, in code and in tests.

The panel floor is holding any Role (`User::canAccessPanel()`); `AuthenticateAdmin` supplies the sentence a role-less (Suspended) User is refused with. `UserFactory` gives every User a permission-less `Member` Role so a gated screen is still asserted honestly, `managingSettings()` grants a Role rather than a Permission, and `suspended()` is the role-less state.

## The Users screen is Owner-only, filing away is a soft delete, and two guards keep an Owner
`UserResource` is Owner-only exactly as `RoleResource` is (`canAccess()` asks `isOwner()`), and for the same reason: handing out access is how somebody with `manage settings` would promote themselves. It sits in the Settings cluster at sort 6, Roles at 7. Roles are given and taken through a plain `roles` form field the pages sync (`UserForm::applyRoles`), **not** the Spatie relationship — binding it would write the pivot ahead of the guards. Assigning a User a Role does not touch the catalogue cache (it changes no Role→Permission map), so there is no `forget()` here.

Suspended (holding no Roles) and Filed away (soft-deleted) are two different states. Suspension is a `roles → []` save; because it is a real state, `EditUser::getSaveFormAction()` requires confirmation when `willSuspendOnSave()` is true, and the `roles` field is `live()` so that re-evaluates as the last box is unticked. Filing away is `DeleteAction` relabelled "File away" (soft delete) with `RestoreAction` ("Bring back") behind `TrashedFilter`; there is deliberately **no** `ForceDeleteAction`, because `StockCount::user()` and `StockMovement::user()` are `withTrashed()` and erasing the row would null the attribution those names carry. A filed-away User is locked out by construction: the auth guard reads Users through the soft-delete scope.

`App\Support\Access\UserGuard` holds the two invariants that keep an installation from bricking itself, wired both at the form save (`EditUser::handleRecordUpdate`, which `halt()`s on refusal) and at the file-away action's `before()`: the last Owner (`User::isLastOwner()`, counting only non-trashed Owners) cannot be demoted, suspended or filed away, and nobody can empty the Roles on their own account. The refusals are the class's constants, shown as a `danger` notification. `EditProfile` is every User's own name and password only — no email field, so no address-change verification runs and the installation sends no mail.
