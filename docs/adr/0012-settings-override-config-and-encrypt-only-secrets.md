# Settings override config, and only secret properties are encrypted

A Setting is a value the Merchant decides once for the whole installation. Settings live in one
table with one row per group, keyed by the group's name and holding that group's whole state as
JSON. Each group is a typed PHP class, read from the container and loaded lazily on first use, so
a request that never touches a group never queries its row.

Two decisions in that shape are the ones a future reader will want explained.

**Config is the default; the row is the override.** Every property of a group has a default, and
for the values that already exist in the application config the default is that config value. A
fresh installation, and the whole test suite, runs with no Settings row at all and behaves exactly
as it did before Settings existed. The `GIOPAGES_*` environment variables therefore keep working,
now as install-time defaults rather than the only source of truth. The alternative, seeding a row in
a migration and removing the config keys, was rejected because an installation with a missing or
mis-seeded row would break at checkout, and because it makes every existing test set up a row it
does not care about. Districts stay in config: a sixty-four item structural list nobody edits per
installation is not a Setting.

**Only properties a group marks as secret are encrypted, individually, inside the JSON.** A
payment key or courier token is unreadable in a database dump; a currency symbol or delivery charge
stays readable in SQL and in the activity log. Encrypting the whole JSON column with an encrypted
cast was rejected because it makes every group opaque to debug, ties non-secret values to `APP_KEY`
for no benefit, and makes redaction in the activity log impossible to do per property. Storing
secrets in plain text was rejected outright.

## Consequences

- Rotating `APP_KEY` requires re-encrypting the secret properties of every Settings row, the same
  obligation any Laravel encrypted data carries. A command for that is expected the first time a
  key rotation is needed.
- The group class is the schema of record for its row. A property with a default can be added to a
  group at any time and old rows keep working; a property cannot be renamed without a data migration.
- A secret never round-trips to the browser. The admin form shows a blank field with a hint that a
  value is stored; blank on save keeps it, a typed value replaces it.
- There is no cache layer. The cache store is the database driver, where a cached copy costs the
  same query as the row itself. Container memoisation per request is the whole optimisation, and a
  cache with forget-on-save is a one-line change if the store ever moves to Redis.
