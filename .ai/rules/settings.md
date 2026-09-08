---
paths:
  - 'app/Support/Settings/**'
  - 'app/Models/Setting.php'
---

# Settings

## Config is the default and the row is the override, so nothing else reads the moved keys
A `SettingsGroup` is a typed class whose constructor sets every property from config, and whose row — one per group, keyed by `key()`, holding the whole group as JSON — is laid over those defaults on `load()`. That is the whole of ADR-0012's first decision, and it has one consequence a reader will trip over: `GeneralSettings::__construct()` is the **only** place in `app/` allowed to read `giopages.currency.*`, `giopages.phone.*`, `giopages.delivery.charge` or `giopages.checkout.*`. Everything else — `Money`, `PhoneNumber`, `CheckoutConfig`'s seeded Delivery Option, `StoreOrderRequest`, `CheckoutShortcode`, the Filament schemas — resolves the group from the container. Reading the config key directly at a call site is not a shortcut; it is a reader that silently ignores what the Merchant saved. Districts stay in config on purpose and are not a Setting.

Hydration is total, like a stored Checkout Config: an unknown key is ignored, a missing one keeps its default, and a scalar is coerced to the property's declared type. So a property with a default can be added to a group at any time with no data migration; a property cannot be renamed without one, because the row would then be read as if it had never been saved.

## The container holds one instance per request, and a save replaces it
`AppServiceProvider` binds each group as a `singleton` whose closure calls `load()`, so the row is queried on the first `app(GeneralSettings::class)` in a request and never before — a public Page that reads no Setting pays no query. `save()` upserts the row and then `app()->instance()`s itself, so the rest of the request reads what was just written. There is no cache facade in the path on purpose (the cache store is the database, so a cached copy costs the row's own query); if the store ever moves to Redis, cache-forever-with-forget-on-save is a change to `load()` and `save()` alone.

Two things follow for tests. The application is rebuilt per test but **not per request inside a test**, so a test that resolves a group, then sets `config([...])`, reads the old defaults; set config before the first resolution, or save through the group. And a Filament schema must read a Setting inside a closure (`->prefix(fn () => ...)`), not as a plain argument, or the value is fixed when the schema is built rather than when the form is drawn.

## Only the properties a group names in `secrets()` are encrypted, and each on its own
`toArray()` encrypts a secret with the application encrypter before it reaches the JSON; `fromArray()` decrypts it on the way back, and a value sealed under a key this installation no longer has reads as blank rather than throwing. The `value` column carries no encrypted cast — the row stays readable in SQL for everything that is not a secret, which is ADR-0012's second decision. `Setting` is the row and nothing more: used only by the group base, no observers, no casts beyond `array`. Rotating `APP_KEY` means re-encrypting every secret property; a command for that is owed the first time it is needed.
