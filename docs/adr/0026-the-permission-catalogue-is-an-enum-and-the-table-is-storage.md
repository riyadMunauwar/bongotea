# The Permission catalogue is an enum, and the table is storage

Every Permission the system knows is a case of `App\Enums\Permission`, carrying its name, its label
and the heading it sits under on the Roles screen. Nothing declares a Permission anywhere else: a
gated Resource, Page, widget or Action references a typed case rather than a string, and the Roles
screen builds its list of ticks from the enum. Spatie's `permissions` table is storage that the
catalogue is written *into*, never the thing the panel reads to find out what exists.
`php artisan permission:sync` writes missing rows and prunes rows whose case is gone.

The shape is chosen against how GioPages is deployed. It is self-hosted by many merchants,
`deploy/README.md` documents web-server config, `storage:link` and `page-cache:flush` and never
mentions `php artisan migrate`, and there is no deploy script anywhere — so there is no pipeline we
control on somebody else's server. If correctness depended on a command a merchant must remember to
run, the failure mode would be an upgraded installation where the new screens are gated behind
Permissions that have no row, so **no Role can ever be granted them** and only the Owner can reach
them. That fails quietly and reads as a bug in our code rather than a missed step in theirs. Having
the Roles screen read the enum removes the failure entirely: the Merchant ticks the new Permission,
the save creates whatever rows it needs, and there was never a step to miss.

**Considered options.** A migration per Permission — what the repo did for `manage settings` — was
rejected because it has two declaration sites by construction, which that migration's own docblock
had to write down: "The literal rather than `SettingsCluster::PERMISSION` ... The two must agree."
At one Permission that is a note; at twelve it is a bug, and a silent one, because a typo gates a
screen behind a name no Role can hold and nothing fails loudly. Having each guarded class declare
its own Permission, discovered by scanning, was rejected because the Roles screen still needs a
label and an order for each one, so that data lands in a second place anyway and a scanning
mechanism has been bought to avoid a list that still has to be written. Syncing lazily at boot was
rejected outright: a database write during request boot, racing across concurrent requests and
fighting the registrar's own cache, is not a reasonable price for a deploy step.

## Consequences

- **The Roles screen must never build its options from the `permissions` table.** Doing so reopens
  the un-synced hole and is the single change that would undo this decision.
- **Renaming a Permission is a fatal at every reference site**, because the references are typed enum
  cases. This is the whole point of the enum over a constant or a literal: the alternative failure
  is a screen that quietly stops being reachable.
- **Deleting a case removes the Permission from every Role** when the sync next runs. An orphaned row
  is worse than a lost record: it draws in the Roles screen as a tick that gates nothing, which tells
  the Merchant a lie about what they are granting.
- **`permission:sync` still earns its place** — pre-creating rows, pruning removed ones, and
  repairing an installation by hand — but nothing is incorrect without it. It ends in
  `PermissionRegistrar::forgetCachedPermissions()`, as the existing permission migration already does.
- **The catalogue is the enum, so the test that matters is over the enum**: every case resolves to a
  gate somewhere, and every gated surface names a case. A test that asserts against rows in
  `permissions` is testing the storage rather than the decision.
- **An Owner is unaffected by any of this.** They bypass `can()` entirely (ADR-0024), so a Permission
  with no row is still theirs — which is what makes an un-synced installation recoverable rather than
  locked.
