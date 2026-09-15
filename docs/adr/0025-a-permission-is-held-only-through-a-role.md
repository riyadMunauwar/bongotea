# A Permission is held only through a Role

A User's authority is the union of the Roles they hold, and nothing else. Spatie's
`model_has_permissions` table — a Permission granted straight to a person — is never written to, and
a User may hold any number of Roles rather than exactly one.

The property being bought is that **what somebody may do has exactly one explanation**. With both
channels open, a User can hold `abandon parcels` for two different reasons and the Roles screen can
only show one of them: taking a Role away would quietly leave the power behind, which is precisely
the failure a permissions system exists to prevent, and it fails silently — nothing errors, the
screen simply still works for somebody who was supposed to have lost it. On a team of two to five
people, the exception that tempts a direct grant is better said as a Role: a Role can be read,
audited and reused, where a per-User tick has to be gone looking for.

This supersedes how `manage settings` was granted. `2026_09_08_061359_create_manage_settings_permission.php`
hands that Permission directly to every User, which was right when Roles did not exist — its own
docblock says as much, that they "will be carried by roles when roles arrive". They have arrived.
The migration stays in place as history of what ran, and grants nothing that matters: the catalogue
and the Owner are established by the seeder instead (GioPages is pre-release, so there is no
installation whose grants need translating).

**Considered options.** Roles plus direct grants — Spatie's default, and what the repo did — was
rejected for the two-explanations problem above. Exactly one Role per User was rejected because it
is the *more* expensive choice, not the cheaper one: `assignRole` is many-to-many out of the box, so
a single-Role rule means adding a constraint, and buying combinatorial Role growth with it — a shop
where one person works Orders, one counts shelves and one does both needs three Roles for two
capabilities. Any-number can always be used as though it were one; one cannot be relaxed later
without a data migration.

## Consequences

- **Holding no Role is a meaningful state, and it means Suspended.** `User::canAccessPanel()` requires
  at least one Role, which overturns that method's founding comment — "Every User is an admin: the
  rule for the panel is *you can log in*" — written when there was nothing finer than logging in.
  Stripping every Role is now how somebody is suspended, which keeps their name on the Stock Counts
  and Adjustments they made where deleting the User would null it away.
- **An exception for one person is a new Role.** There is no supported way to give one User one extra
  Permission, and adding one would reintroduce exactly what this decision removes.
- **`UserFactory::managingSettings()` grants a Role**, not a Permission, and the tests using it move
  with it. A factory state that calls `givePermissionTo()` is a sign this decision has been broken.
- **Removing a Permission from a Role takes effect on the next request**, so every write that touches
  Roles or their Permissions ends in `PermissionRegistrar::forgetCachedPermissions()`. Without it a
  revoked Permission keeps working until the cache expires, which makes the guarantee above false in
  the one situation — revoking access in a hurry — where it matters most.
- **A Role cannot be deleted while any User holds it.** One click would otherwise revoke access for
  several people at once, and afterwards nothing records who they were. Unticking a Permission needs
  no such guard: it is visible, reversible and affects one thing.
