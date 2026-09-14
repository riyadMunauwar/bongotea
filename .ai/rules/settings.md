---
paths:
  - 'app/Support/Settings/**'
  - app/Models/Setting.php
  - 'app/Filament/Clusters/Settings/**'
  - app/Providers/Filament/AdminPanelProvider.php
  - 'app/Support/Reporting/**'
---

# Settings

## Config is the default and the row is the override, so nothing else reads the moved keys
A `SettingsGroup` is a typed class whose constructor sets every property from config, and whose row — one per group, keyed by `key()`, holding the whole group as JSON — is laid over those defaults on `load()`. That is the whole of ADR-0012's first decision, and it has one consequence a reader will trip over: `GeneralSettings::__construct()` is the **only** place in `app/` allowed to read `giopages.currency.*`, `giopages.phone.*`, `giopages.delivery.charge`, `giopages.checkout.*` or `giopages.timezone`. Everything else — `Money`, `PhoneNumber`, `CheckoutConfig`'s seeded Delivery Option, `StoreOrderRequest`, `CheckoutShortcode`, `ReportingWindow`, `OrdersTable`'s date filter, the Filament schemas — resolves the group from the container. Reading the config key directly at a call site is not a shortcut; it is a reader that silently ignores what the Merchant saved. Districts stay in config on purpose and are not a Setting.

Hydration is total, like a stored Checkout Config: an unknown key is ignored, a missing one keeps its default, and a scalar is coerced to the property's declared type. So a property with a default can be added to a group at any time with no data migration; a property cannot be renamed without one, because the row would then be read as if it had never been saved.

## The container holds one instance per request, and a save replaces it
`AppServiceProvider` registers one `beforeResolving(SettingsGroup::class, ...)` hook that binds whichever subclass is being resolved as a `singleton` calling `load()`, so the row is queried on the first `app(GeneralSettings::class)` in a request and never before — a public Page that reads no Setting pays no query. A new group therefore needs no provider line and no test `beforeEach`; adding one to a group or a fixture is a sign the hook has been broken. `save()` upserts the row, `app()->instance()`s itself so the rest of the request reads what was just written, and then forgets every cached public Page through `PageCacheInvalidator::forgetEveryPage()`, because the currency symbol, decimals and quantity ceiling are baked into a cached render. A test that primes the cache and then saves a group must not flush by hand; the save is what proves the invalidation. There is no cache facade in the path on purpose (the cache store is the database, so a cached copy costs the row's own query); if the store ever moves to Redis, cache-forever-with-forget-on-save is a change to `load()` and `save()` alone.

## A row must never throw, so a null reads as the property's empty value
`coerce()` maps a stored `null` against a non-nullable scalar property to `''`, `0`, `0.0` or `false`. A `TypeError` from `load()` would 500 every request, including the Settings page that could repair the row, so no per-field `dehydrateStateUsing` bandaid is needed on a page for a field Filament hands over as null (the blank trunk prefix relied on one before). The currency decimals field is disabled once a Product or Order exists, trashed ones included, because every stored amount is an integer in minor units at that scale; a disabled field is left out of the saved state, so `with()` keeps the stored value.

Two things follow for tests. The application is rebuilt per test but **not per request inside a test**, so a test that resolves a group, then sets `config([...])`, reads the old defaults; set config before the first resolution, or save through the group. And a Filament schema must read a Setting inside a closure (`->prefix(fn () => ...)`), not as a plain argument, or the value is fixed when the schema is built rather than when the form is drawn.

## Only the properties a group names in `secrets()` are encrypted, and each on its own
`toArray()` encrypts a secret with the application encrypter before it reaches the JSON; `fromArray()` decrypts it on the way back, and a value sealed under a key this installation no longer has reads as blank rather than throwing — but it is logged, `hasSecret()` still says a value is stored, and the group remembers the ciphertext so that `toArray()` writes it back unchanged when the property is still blank. Only a typed replacement discards it. The `value` column carries no encrypted cast — the row stays readable in SQL for everything that is not a secret, which is ADR-0012's second decision. `Setting` is the row and nothing more: used only by the group base, no observers, no casts beyond `array`. Rotating `APP_KEY` means re-encrypting every secret property; a command for that is owed the first time it is needed.

`secrets()` is public because it is read from three places, and `SettingsGroup` is the only one that should ever hold a plain secret. `changesSince()` names a changed secret with `null` on both sides, so the activity log records that it was rotated and nothing else. `fromArray()` is the *stored* shape (it unseals secrets); a form hands over plain values, so the page goes through `$previous->with($values)`, never `fromArray()` — passing a typed secret to `fromArray()` would try to decrypt it and read blank.

## A secret's form field is made write-only by the page base, from the group's list — declare a plain `TextInput`
`SettingsPage::form()` walks `getFlatFields()` after the components are set and turns every field named in `secrets()` into a revealable password input with a "value is stored" hint; `mount()` blanks those keys before `fill()`, `save()` drops a blank secret from the values so `with()` keeps the stored one, and blanks them again afterwards so a just-typed secret does not ride in the snapshot. Nothing on the concrete page says "secret". Two things follow: a secret field must be a `TextInput` (anything else throws a `LogicException` when the form is built), and there is no way to clear a stored secret from the form — blank means keep. A revealable password input binds its `type` in Alpine (`x-bind:type`), so a test asserts on `fi-revealable`, not on `type="password"`. `tests/Fixtures/Settings/` holds the group and page that prove all of this; the General group has no secrets.

## The timezone Setting reaches the panel through one closure, and the row is guarded against a name PHP does not know
`AdminPanelProvider::boot()` sets `FilamentTimezone::set(fn () => app(GeneralSettings::class)->timezone)` once, so every `dateTime()` column and entry in the panel is drawn in the Merchant's timezone without a per-column `->timezone()`. Do not add one; a column that names its own zone silently disagrees with the rest of the screen. The closure is what keeps a request that draws no date from resolving the group. Storage stays UTC, `config('app.timezone')` stays UTC, and only display and day boundaries move: `OrdersTable`'s date filter and `ReportingWindow` both step whole days in local time and convert to UTC instants for the query — never `whereDate()`, which cuts at UTC midnight.

`GeneralSettings::with()` is the one override of the base's hydration: a `timezone` the row holds that is not in `timezone_identifiers_list()` (a hand-edited row, a null, a blank) reads as the group's current value — the config default on a fresh group — because a bad zone would otherwise throw from Carbon on every screen that draws a date. The page's `Select` refuses such a value before it reaches the row. Tests that need "today" to fall differently save the group with `timezone = 'UTC'`; an Order at 17:30 UTC is 23:30 in Dhaka, one at 18:30 UTC is 00:30 the next day.

## Payment settings follow one naming rule, and the usable-method rule lives in `refusal()`
`PaymentSettings` names a method's properties from the enum value in camel case plus a suffix — `cashOnDeliveryEnabled`, `sslcommerzSandbox`, `sslcommerzLogo` — and a credential is any secret starting with that prefix, so `credentialsFor()` needs no table and a gateway's `credentialProperties()` must spell the same names. A method with no `…Enabled` property has no settings yet and reads as "not available yet"; that is how a gateway is held off without a list anywhere (PayStation was, until #75), and the Payment Methods page hides its "Coming later" section once nothing is. Adding a gateway's settings is its properties plus its credentials in `secrets()`, nothing else; a host a gateway hands over at onboarding (bKash's two) is a plain property with the usual host as default, not a credential.

`refusal()` is the only wording of "usable": on, every credential filled, and, for an online method, the currency is BDT. `PaymentMethod::isUsable()` delegates to it, `CheckoutConfig::offeredMethods()` reads that, and the Checkout tab prints the same sentence beside a listed method it cannot draw — ask the group for the refusal, never restate the rule. `isUsable()` deliberately does not ask whether a driver exists: a method whose settings are filled is offered, and an Order under it captures Unpaid and lands on the Retry Page until the driver arrives. A replacement logo is a path on the public `local` disk under `payment-methods/` and nothing more; `PaymentMethod::logoUrl()` goes through the group so the checkout draws it, and a settings save already flushes every cached page.

## The sidebar order is declared by every entry, and the Pages funnel is ours on purpose
Every top-level navigation entry states its own `$navigationSort` — Dashboard 0, Orders 1, Shipments 2, Products 3, Pages 4, InventoryCluster 10, ReportsCluster 50, SettingsCluster 100: the work, then the reading, then the configuring — Inventory is work rather than reading, which is why it sits above Reports. Adding an entry in the middle renumbers everything below it, which is a deliberate edit of several files plus the test, never an increment tacked on the end: Shipments sits under Orders because a parcel is what became of one. This is total on purpose: Filament reads an undeclared sort as `-1`, so a resource generated without one does not land at the end, it lands above the Dashboard. `AdminShellTest` asserts the whole order (with a `manage settings` User, or the last entry is hidden).

The three clusters sit at 10, 50 and 100 rather than at 5, 6 and 7 so that a category arriving later has somewhere to go without renumbering the five resources above them — that gap is what Reports and then Inventory were put in. Inside a cluster the same scheme runs separately and what it holds never reaches the top-level list: Settings holds General 0, Payment Methods 1, Destinations 2, Tracking 3, Couriers 4; Reports holds Courier Report 0; Inventory holds Stock Counts 0.

Reports is ungated where Settings is gated, and that asymmetry is deliberate: the Dashboard already draws Sales, Average Order Value and courier money to every User, so a permission on the cluster would stop nobody reading the same class of figure one click away, and a gate a Merchant walks around is worse than none. A `view reports` permission becomes worth adding on the day the Dashboard's own figures are gated with it.

Pages is drawn with `giopages-funnel`, a custom Blade Icons set registered in `AdminPanelProvider::boot()` from `resources/svg/`. Do not "simplify" it to `Heroicon::OutlinedFunnel`: that enum is Filament's filter-action icon, and `PagesTable` has filters, so the sidebar item and the filter button on the screen it opens would be the same glyph. Blade Icons ships with Filament — the set is a registration, not a dependency.

The funnel is a wayfinding metaphor borrowed from CartFlows and nothing more. `CONTEXT.md` keeps "funnel step" on Page's avoid-list deliberately (a CartFlows funnel is multi-step; a Page is a complete standalone surface), and the icon is not a licence to start writing the word in code — nor is the glossary a reason to delete the icon.

Icons are drawn to Heroicons' outline spec (24×24, `fill="none"`, `stroke-width="1.5"`, round caps/joins) so the five glyphs read as peers. A filled glyph among outlined ones becomes an accent the sidebar never chose — which matters most in the collapsed 3.5rem rail, where the icon is the only label.

## A group property that is an array must be sanitised before parent::with()
`SettingsGroup::coerce()` only casts scalars against a declared type, so a row holding a string where a group declares `array` reaches `setValue()` and throws — and a `TypeError` from `load()` 500s every request, including the page that could repair the row. `TrackingSettings::with()` therefore normalises `eventDefaults` **before** calling `parent::with()`, not after (after is too late; the assignment has already happened).

A Toggle reads an absent value as off, so a group whose default is "on when the row says nothing" cannot rely on the field's `default()`: `Tracking::mount()` fills every declared event from `reportsByDefault()` after `parent::mount()`.

## A beacon rate limit of nothing is the beacon switched off
`SettingsGroup::coerce()` reads a stored null as an int property's empty value, so a damaged row would hand `Limit::perMinute(0)` to the beacon limiter and refuse every report silently — the one failure the whole tracking design is arranged against. `TrackingSettings::with()` therefore floors `beaconRateLimit` at 1, falling back to the config default, the way `GeneralSettings::with()` guards an unknown timezone. The page's `minValue(1)` only covers values that went through the form.

## Visits are a whole-window figure and never a bucket, and null means "not counted"
`OverviewFigures::$visits` is nullable and is filled by `withVisits()` **after** the buckets are summed — `plus()` never carries it. The aggregate counts a day and a one-day window's buckets are hours, so a bucket's Visits are not a figure that exists; that is why the Visits tile alone draws no sparkline. Do not "fix" it by storing an hourly aggregate.

Null is not zero. `ReportingQuery::visitsIn()` / `visitsByPageIn()` answer null when `TrackingSettings::$countsVisits` is off, and `PageLeaderboardRow::$visits` is also null for a Page deleted for good (its counts went with it; its Orders sit under a slug). `conversionRate()` on `HasSalesRatios` returns null for null visits and 0.0 for zero — screens must say "Not counted" rather than draw 0.0%, which a Merchant reads as a Page that sells nothing.

Visit days are compared as `Y-m-d` strings against `$window->localStart()/localEnd()`, never the UTC instants Orders are cut on: the aggregate is keyed by the Merchant's day.

## Courier settings follow the payment naming rule, and Pathao's token is not a Setting
`CourierSettings` names a courier's properties from the enum value in camel case plus a suffix — `steadfastEnabled`, `pathaoClientId`, `redexParcelWeight` — so `credentialsFor()` needs no table and a driver's `credentialProperties()` must spell the same names. A credential is any secret starting with that prefix followed by a capital.

Every courier carries the same five defaults (`CourierSettings::DEFAULTS`: pickup store, delivery type, item type, parcel weight, declared value). Weight and declared value are not decoration — RedEx refuses a parcel without both and no Checkout asks a Customer how heavy their order is, so these are the only answer there is. Which of the five a courier actually sends is its driver's business.

Pathao's OAuth access token is deliberately not a property here: it is derived state a machine issues and expires, and a settings group holds values a human owns. It is cached by the driver that obtained it.

## Needs Attention's parcel conditions restate no rule of their own
`ReportingQuery::needsAttention()` has six conditions now; the two parcel ones borrow their rules rather than re-deriving them. "A parcel came back and nobody decided" is `Order::notYetSent()` (the delivery queue's own scope) plus "not Cancelled", dated by the returned row's `updated_at` — a terminal attempt is never polled again, so that is when it came back. "Nobody has heard about it in a week" is the `stuck_at` `CourierSweep` writes; never re-measure `CourierSweep::STUCK_AFTER_SECONDS` here, or the list and the sweep disagree the day the horizon moves.

Both are Active-aware, so abandoning or re-sending clears the row by itself, and the two can never list the same Order at once.

## Courier figures are cut on booked_at, borrow their rules, and are never blended
`ReportingQuery::courierPerformance()` reads Shipments, not Orders, and `parcelsSentIn()` cuts them on `booked_at` — the one instant a Courier says it has the parcel. A row a batch wrote and the sweep has not offered, and a row a Courier refused, both hold no `booked_at`, so SQL leaves them out of the range and neither is excluded by hand. A parcel later written off stays sent: it cost money.

Nothing there re-derives a rule. "Arrived" is `Shipment::arrived()`; "when it arrived" is the row's `updated_at`, which is what `needsAttention()` already reads a return's date off, because a terminal attempt is never polled again.

Days to delivery is the one figure counted in PHP rather than in SQL: no arithmetic over two instants is spelled the same way on every driver, and the bucket-boundary CASE every other figure leans on cannot stand in for a duration. It loads the window's arrived parcels and two columns of each.

Never add a total row to the Courier Report (ADR-0018). Counts are zero where a Courier carried nothing; a **rate** with no parcels under it and a **charge** no Courier has named are null, and the widget draws a sentence for each — a nought in a money column reads as free carriage.

## A Select refuses a value it does not offer, so a field fed by a Repeater must settle its own state
Filament's `Select` adds an implicit `in:` rule from its options (`Select::getInValidationRuleValues()`: a state whose option label is blank is invalid). So a Select whose options come from a sibling Repeater is *refused* the moment the Merchant deletes or renames the row it names — an error on a field they never touched, and the group's own guard (`CourierSettings::settleDefaultPickupStore()`) never runs, because validation fails before `with()` does. `Couriers::settleDefaultStore()` is the answer: the Repeater's `afterStateUpdated` repoints the default at a store that still exists (null where none does). The group keeps its guard for rows that never came through the page; both are needed.

A form value a component reshapes on hydration — a Repeater keys its rows — must be set in `SettingsPage::formValues()`, which `mount()` fills from. Writing it into `$this->data` after `mount()` (as `Tracking` does for plain nested toggles) is too late for those.

In tests, `fillForm()` merges into a Repeater's uuid-keyed state rather than replacing it, so a deleted row is `->set('data.<property>', [...])` — which is also what the browser sends.

## Name the schema before reading anything off it, or every dynamic dropdown opens empty
Filament keys a page's schema in `cacheSchema()`, *after* `form()` returns, and a component's key is frozen the first time it is read. `SettingsPage::form()` walks `getFlatFields()` to make secrets write-only, which reads the container's key — so without an explicit `$schema->key('form')` first, every field freezes as `data.<property>` (the state path) instead of `form.<property>`.

Those keys are what the browser calls back with, and `getSchemaComponent()` resolves a key by the schema name in front of the dot: a `data.` key names no schema, so `callSchemaComponentMethod()` answers null. A `Select` whose `options()` is a closure asks for its options over exactly that call when the dropdown opens, so it silently opens empty while the server-side options and the implicit `in:` rule are both fine — the page still saves, and tests that only `fillForm()` stay green.

`SecretSettingsTest` locks it: every field's key starts with `form.` and resolves back. Any schema method that reads its own schema (flat fields, keys, components) must name the schema first.
