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
---

# Access

## Access: the enum is the catalogue, a Role is the only channel, the Owner bypasses
`App\Enums\Permission` is the single declaration site for every Permission (ADR-0026). A gate names a typed case — never a string literal, never a migration per Permission. The `permissions` table is storage the enum is written into: `PermissionCatalogue::ensure()` creates a row when something first grants it, `permission:sync` pre-creates and prunes, and nothing is incorrect on an installation that never ran it. Never build a list of Permissions by reading the table.

Authority comes from Roles and nothing else (ADR-0025). `User::hasDirectPermission()` is hard-coded false, so a row in `model_has_permissions` — the old `manage settings` migration left some — decides nothing, and nothing writes that pivot. An exception for one person is a new Role, not a tick. Every write touching Roles or their Permissions ends in `PermissionCatalogue::forget()`.

The Owner is a Role by name (`Role::OWNER`, made through `Role::owner()`) holding no Permission rows; a `Gate::before` hook in `AppServiceProvider::boot()` answers true for it before the map is consulted (ADR-0024), so a Permission with no row is still theirs. Never ask the pivot or `hasPermissionTo()` what an Owner may do — ask `can()`, in code and in tests.

The panel floor is holding any Role (`User::canAccessPanel()`); `AuthenticateAdmin` supplies the sentence a role-less (Suspended) User is refused with. `UserFactory` gives every User a permission-less `Member` Role so a gated screen is still asserted honestly, `managingSettings()` grants a Role rather than a Permission, and `suspended()` is the role-less state.
