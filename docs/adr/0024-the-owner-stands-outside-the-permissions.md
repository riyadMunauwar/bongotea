# The Owner stands outside the Permissions rather than holding every one

Access to the admin is decided by Permissions, each one a working area or a single act that cannot
be undone, and each held only through a Role. The **Owner** is the exception, and the shape of that
exception is the decision: an Owner holds no Permission at all and is instead a bypass — a
`Gate::before` hook that answers true for any User carrying the Owner role, before the Permission
map is consulted.

The reason is what a Permission *is* here. Permissions are declared in code and arrive as features
are built (ADR-0026), so the catalogue grows on every release. A role that "holds everything" is
therefore only ever correct as of the last time somebody ticked a box: ship `abandon parcels`, and
the screen it guards is invisible to the one person who could grant it, on an installation where
nobody else can reach the Roles screen either. A bypass makes *holds everything* true by
definition and true in advance, which is the property that keeps a growing catalogue safe — a new
Permission is deny-by-default for every other Role, which is what we want, and already the Owner's,
which is what keeps the installation operable.

**Considered options.** An ordinary Role with every Permission ticked was rejected for the drift
above; the obvious repair — having the sync auto-tick that Role — is this decision with extra steps
and a table that can fall out of step in between. A boolean column on `users` was rejected for a
different reason: it puts authority on an axis the Roles screen cannot show, so "who can do
everything here?" stops being answerable in the panel, and that question is one a Merchant should
never have to run SQL for. The role is what makes the answer visible; the bypass is what makes it
correct.

## Consequences

- **The Owner role holds no Permission rows, and the Roles screen draws none for it.** Ticks would
  be a lie in both directions: unticking one would change nothing, and a Permission added later
  would show as unticked while being held. The row is drawn as locked, saying that it is everything.
- **The role is protected.** It cannot be deleted or renamed, and the last User holding it cannot be
  demoted, suspended or filed away — an installation with no Owner cannot be repaired from inside
  the panel, because the screen that would repair it is the one nobody can reach.
- **`php artisan bongotea:owner {email}` is the bootstrap and the only recovery.** A future installer
  (GUI and terminal) creates the first Owner through it; until then it is how any installation gets
  its first one, and it is what answers "the only Owner has left the company" without a database
  client. It is the one route into the panel that does not go through the panel, and that is
  deliberate.
- **The bypass is total.** `Gate::before` answering true covers every `can()` in the application,
  including policies written years from now. That is intended: an Owner who could be refused
  something is not an Owner, and a second place that decides what an Owner may do is the drift this
  decision exists to prevent.
- **Never ask the database what an Owner may do.** `$user->hasPermissionTo(...)` and the pivot tables
  both answer no for an Owner and both are the wrong question. Every gate, and every test of one,
  goes through `can()`.
