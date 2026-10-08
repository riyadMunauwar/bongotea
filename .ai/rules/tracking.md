---
paths:
  - 'app/Support/Tracking/**'
  - 'app/Models/Destination.php'
  - 'app/Models/TrackingEvent.php'
  - 'app/Models/TrackingContext.php'
  - 'app/Observers/DestinationObserver.php'
  - 'app/Enums/Tracking*.php'
  - 'app/Console/Commands/*Tracking*.php'
  - 'app/Filament/Resources/Destinations/**'
  - 'tests/Fixtures/Tracking/**'
---

# Tracking

## A Moment writes rows; only the sweep speaks to a Provider
Nothing in a web request may call an ad platform. `MomentRecorder` writes one outbox row per applicable Destination per provider event and returns; `TrackingSweep` (via `tracking:sweep`, scheduled beside `payments:sweep` with overlap protection) is the only caller of `Provider::send()`. Follow the payment conventions exactly: injected `Illuminate\Http\Client\Factory`, named timeout constants, no in-request retry.

States mirror `PaymentState`. A `ConnectionException` or a 5xx is `Delivery::unreachable()` and leaves rows Pending — never Failed — so a broken cron or a brief outage loses nothing; only the Provider's own 4xx refusal is `Delivery::refused()` and writes Failed. A row older than the Provider's `acceptanceWindowSeconds()` (measured from `occurred_at`, not `created_at`) is Expired before the refusal check, so a Destination nobody fixes still drains.

Idempotence is the unique index on (`destination_id`, `deduplication_id`, `event`), not a check at the call site — the recorder uses `firstOrCreate`, so the three places a Verification runs from can each write and only the first lands. `MomentRecorder` is where the Purchase rule lives, not the Provider — `eventsFor()` maps both Moments to `Purchase` and knows nothing about how an Order is paid.

`MomentRecorder::captured()` asks `purchaseMomentFor()` and records the Capture Moment for a Cash on Delivery Order — it is the call a checkout makes once `OrderCapture` has committed, with the request's `TrackingContext`, wrapped in `report()` so tracking never fails an Order (nothing on the storefront captures yet, so only tests make it today); `MomentRecorder::paid()`, called by `PaymentVerifier::verify()` for whatever left a Payment Paid, asks nothing and simply records — the index is what makes it one Purchase, so an Order that already reported at Capture finds its own row in the way and three simultaneous Verifications write once. Do not re-guard `paid()` on `purchaseMomentFor()`: an Order switched to Cash on Delivery while a Payment was Pending can still end Paid (see `.ai/rules/payments.md`), and guarding would report nothing for it. The call sits outside the Order's lock and is wrapped in `report()`, because money that has moved must never fail to settle over an outbox row.

The outbox row holds **no personal data**: `Facebook::payloadFor()` reads the Customer off `$event->order` at delivery, which is what lets the table be pruned without touching a fulfilment record. Match keys are SHA-256 hashed after Meta's normalisation; `client_ip_address`, `client_user_agent`, `fbc` and `fbp` must stay plain, because a hashed one matches nobody.

Which Destinations apply is `TrackingPlans::current()` and nowhere else: each Provider's default Destination, switched on. A Destination that is switched off yields no rows at all (that is a campaign break); one that is on with blank credentials still accumulates them and the sweep prints the Provider's refusal. Why a Destination is unusable has one wording, `Provider::refusal()` — the sweep, the table column and any later screen ask it rather than restating the rule.

`DestinationObserver` keeps exactly one default per Provider, which a model does not get for free: the first one takes it, marking a second unmarks the first, and deleting the holder passes it on.

Tests run as a Pest dataset over `tests/Fixtures/Tracking/ProviderScript`; a new Provider is a subclass plus a row in `tests/Datasets/TrackingProviders.php`, never a new test file. `Http::fake()` stubs stack with the first answering callback winning, so recovering from `fakeOutage()` flips a flag inside that one fake (`endOutage()`) rather than laying a second fake.

## A Tracking Context is kept only where the Moment that needs it has no browser
`App\Models\TrackingContext` is both the row and the thing passed around: `none()` and `fromRequest()` build one unsaved, `MomentRecorder::write()` copies it onto an outbox row, and only `captured()` ever saves one — for an Order whose Purchase is still to come, because the Verification that reports it runs from the sweep or a gateway's Notification with nobody there to ask, and no Capture row exists to copy from. An Order reporting at Capture keeps nothing (its outbox row already holds the same things), and a store with no applicable Destination keeps nothing at all — an IP address with no purpose is not collected. `keepFor()` is `firstOrCreate`, so the first visit's word stands.

Which click identifiers are kept is `Provider::clickIdNames()` and nowhere else — `TrackingContext` takes the union across Providers and drops the rest, so adding TikTok adds `ttclid` in the driver alone. They are filtered and truncated, never validated: they arrive in a field no Customer can see, from a script their browser may not have run, and no Capture rule may refuse an Order over one, because an Order refused over one is a sale lost to tracking.

## The browser's half is one snippet, built here and never authored in a view
`TrackingSnippet` builds the whole `<script>`: the consent hook, and one `Provider::script()` per Destination from `TrackingPlans::current()` — the same answer the recorder takes, so the two halves cannot disagree about where the store reports or about what it reports there. A Provider's `script()` returns its base code (which reports the page's own load) plus one call per `BrowserEvent`, and returns '' where the Destination has no public identifier; the access token is not asked for, because that is the server half's credential. No Destination means no element, no global and no external request at all. Today the Order Complete and Retry Pages are the only documents that carry it (`forOrder()`).

Both copies of an event carry the order number as the deduplication id (`eventID` on Meta), which is the whole of how one Purchase is counted once. Which Moment an Order's Purchase belongs to is `MomentRecorder::purchaseMomentFor()` and is not restated at a call site.

The consent hook is `TrackingSnippet::CONSENT_HOOK`, one global, consulted once. Absent or true fires; only an explicit `false` holds. Never invert that default — it would silently disable reporting for every Merchant who never heard of it.

A deduplication id the browser makes up for a page load cannot be baked in where one document is served to everyone. `BrowserEvent::forLoad()` and `forSelection()` carry JavaScript *naming* the value instead, and every Provider writes it through `deduplicationIdSource()` rather than quoting the string. `Facebook::script()` skips its own bare `PageView` when the events given already include one, so a load is reported once and with that id.

## One Plan answers where the store reports and what it reports there
`TrackingPlans::current()` answers with a `TrackingPlan`, not a list: the Destinations, the events, and whether the basket travels are read together, and the recorder and the snippet builder must not read them separately. Which events fire is `TrackingSettings::reportsByDefault()`; there is no per-Page Tracking Config any more. `TrackingPlan::sendsContentDetail()` reads `TrackingSettings::$namesProducts` (default on).

The Tracking settings page is the whole store-wide Tracking Config, including each Provider's default Destination — but that choice is stored as `destinations.is_default` and nowhere in the settings row. The page reads it in `formValues()` and marks the chosen row in `valuesToSave()`, leaving `DestinationObserver` to unmark the rest; do not add a `defaultDestinations` property to `TrackingSettings`, or deleting a Destination leaves the row pointing at nothing.

`loadEvent()` on the Provider contract lets a caller ask whether the load a base code reports by itself is still wanted without ever spelling the name, and `payloadFor()` prints exactly what `send()` would send. Content detail is frozen onto the outbox row (`content_detail`) at Moment time — a driver reads the row it is handed, and the setting may have changed by the time the sweep runs.

## Checkout Started is priced from the catalogue, never from the beacon
The beacon reports Checkout Started with `items=id:quantity,…` and is trusted for Product ids and counts and for nothing else: `TrackingController::selection()` prices them off the Products themselves, so a hand-made post naming a hundred things reports only what the store sells, at its own prices. A quantity outside what a checkout would take is brought back inside it; a request naming no items reports an empty Selection worth nothing. Nothing on the storefront raises the Moment yet.

`Selection` (`App\Support\Tracking\Selection`) is the one domain answer to "what was being bought and what it is worth", frozen onto `tracking_events.selection` where there is no Order.

An InitiateCheckout's value is what the selected Products come to and carries **no** Delivery Charge, unlike a Purchase's. The Charge is what it costs to bring the parcel rather than part of what was chosen, and it enters a reported value at the Purchase, where the figure has to agree with the Merchant's own Sales. Do not "fix" that by adding the delivery amount.

`browserEventsIn()` keeps the raw call under `source` (`ProviderScript` asks for it; `FacebookScript` supplies it), because an event whose parameters are worked out as it fires has none to decode; `reportsLiveSelection()` and `namesWhatWasBought()` are how a test asks about one.
## A Visit is counted as an increment at the beacon, and there is no rollup to protect
`VisitCounter` is the only writer of `visit_counts` (one row per Merchant-timezone day, for the whole store). `MomentRecorder::reportedByBrowser()` calls it before the plan check, so a store with no Destination and one whose `PageView` is switched off both still count — the Merchant's own figure is not an ad platform's to decide.

The switch is `TrackingSettings::$countsVisits`, default off. `VisitCounter::count()` asks whether to write — the only place that decides whether a load is counted — and `ReportingQuery::countsVisits()` asks whether there is a figure to give, answering null rather than zero when there is not (see `.ai/rules/settings.md`). Do not collapse them: they are two questions, not one.

The aggregate is written at report time, not rolled up from `tracking_events`. There is nothing to roll up: rolling up outbox rows would count nothing for a store with no Destination, would over-count (one row per provider event), and would leave the tile blank until the nightly command ran. `tracking:prune` must simply never touch `visit_counts`.
## Retention is bound to purpose, and prune never touches what is still owed
`TrackingPrune` holds every retention as a constant, never a Setting. Sent outbox rows 7 days, Failed and Expired 30 (the diagnostics screen is what reads them), Tracking Contexts the longest `Provider::acceptanceWindowSeconds()` across Providers — asked of the drivers rather than written down as seven days, so the number and the reason cannot drift and a Provider added later moves it on its own.

Two things must never be deleted. **Nothing Pending**, at any age: a Merchant whose cron has been broken for a week must find the backlog and see all of it go out on the first run after it is fixed, and only the sweep may settle a row. **`visit_counts`**, ever: it is written at report time by `VisitCounter`, so it is already complete before prune runs. #103/#118 call for "rollup before prune"; there is nothing to roll up, and deriving it from the outbox would be wrong as well as late (a store with no Destination writes no row, and two provider events per load would double-count). If anything is ever added that a kept figure is derived from, derive it inside `TrackingPrune::run()` before the delete that removes it.

Outbox rows are cut on `updated_at` — the instant the sweep settled them, for all three settled states alike. Contexts on `created_at`, written once at Capture. Deletes are batched at 1000 because a store under paid traffic writes a row per provider event per load.

`DeliveryHealth` answers the diagnostics screen's four questions and records when the sweep last ran. That marker is written by `TrackingSweep::run()` every time, whatever it found — an empty outbox and a scheduler that never fired look identical from the rows, and telling those apart is the whole point. It lives in the cache (`tracking.swept_at`) because it is operational state, not a domain record; the scheduler already depends on the cache for the sweep's overlap mutex.

## Delivery diagnostics ride on the Destinations screen, and a widget table there needs its own paging key
`DeliveryHealthStats` (header) and `RecentDeliveryFailures` (footer) hang off `ListDestinations`, not the Dashboard: anything under `app/Filament/Widgets` is discovered onto the panel and drawn on the Dashboard, and this is an operator's "is my tracking working" question, asked beside the list that already names an unusable Destination. Both `canView()` on a Destination existing, so a fresh installation is never told its scheduler is broken. They label and never count — the figures are `DeliveryHealth`'s.

`RecentDeliveryFailures` is query-backed (settled rows, possibly thousands), so it needs neither `PaginatesRows` nor its clamp — but it does need `->queryStringIdentifier('failures')` **on the table**. Filament names every table's page plainly `page` otherwise and Livewire reads that off the request, so the Destinations list above would hand its page number down and Filament would draw the *empty state* — a green tick over a list of failures. Note that overriding `getTableQueryStringIdentifier()` on the component does nothing in Filament v4: `InteractsWithTable::makeTable()` never reads it.
