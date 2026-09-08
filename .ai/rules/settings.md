---
paths:
  - 'app/Support/Settings/**'
  - 'app/Models/Setting.php'
  - 'app/Filament/Clusters/Settings/**'
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

`secrets()` is public because it is read from three places, and `SettingsGroup` is the only one that should ever hold a plain secret. `changesSince()` names a changed secret with `null` on both sides, so the activity log records that it was rotated and nothing else. `fromArray()` is the *stored* shape (it unseals secrets); a form hands over plain values, so the page goes through `$previous->with($values)`, never `fromArray()` — passing a typed secret to `fromArray()` would try to decrypt it and read blank.

## A secret's form field is made write-only by the page base, from the group's list — declare a plain `TextInput`
`SettingsPage::form()` walks `getFlatFields()` after the components are set and turns every field named in `secrets()` into a revealable password input with a "value is stored" hint; `mount()` blanks those keys before `fill()`, `save()` drops a blank secret from the values so `with()` keeps the stored one, and blanks them again afterwards so a just-typed secret does not ride in the snapshot. Nothing on the concrete page says "secret". Two things follow: a secret field must be a `TextInput` (anything else throws a `LogicException` when the form is built), and there is no way to clear a stored secret from the form — blank means keep. A revealable password input binds its `type` in Alpine (`x-bind:type`), so a test asserts on `fi-revealable`, not on `type="password"`. `tests/Fixtures/Settings/` holds the group and page that prove all of this; the General group has no secrets.
