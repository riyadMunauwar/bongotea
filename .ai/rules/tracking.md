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

`MomentRecorder::captured()` asks `purchaseMomentFor()` and records the Capture Moment for a Cash on Delivery Order; `MomentRecorder::paid()`, called by `PaymentVerifier::verify()` for whatever left a Payment Paid, asks nothing and simply records — the index is what makes it one Purchase, so an Order that already reported at Capture finds its own row in the way and three simultaneous Verifications write once. Do not re-guard `paid()` on `purchaseMomentFor()`: an Order switched to Cash on Delivery while a Payment was Pending can still end Paid (see `.ai/rules/payments.md`), and guarding would report nothing for it. The call sits outside the Order's lock and is wrapped in `report()`, because money that has moved must never fail to settle over an outbox row.

The outbox row holds **no personal data**: `Facebook::payloadFor()` reads the Customer off `$event->order` at delivery, which is what lets the table be pruned without touching a fulfilment record. Match keys are SHA-256 hashed after Meta's normalisation; `client_ip_address`, `client_user_agent`, `fbc` and `fbp` must stay plain, because a hashed one matches nobody.

Which Destinations apply is `TrackingPlans::forPage()` and nowhere else. A Destination that is switched off yields no rows at all (that is a campaign break); one that is on with blank credentials still accumulates them and the sweep prints the Provider's refusal. Why a Destination is unusable has one wording, `Provider::refusal()` — the sweep, the table column and any later screen ask it rather than restating the rule.

`DestinationObserver` does two things a model does not get for free: keeps exactly one default per Provider (first one takes it, marking a second unmarks the first, deleting the holder passes it on), and calls `PageCacheInvalidator::forgetEveryPage()` on save and delete, because the dataset is baked into every cached Page's Pixel snippet.

Tests run as a Pest dataset over `tests/Fixtures/Tracking/ProviderScript`; a new Provider is a subclass plus a row in `tests/Datasets/TrackingProviders.php`, never a new test file. `Http::fake()` stubs stack with the first answering callback winning, so recovering from `fakeOutage()` flips a flag inside that one fake (`endOutage()`) rather than laying a second fake.

## A Tracking Context is kept only where the Moment that needs it has no browser
`App\Models\TrackingContext` is both the row and the thing passed around: `none()` and `fromRequest()` build one unsaved, `MomentRecorder::write()` copies it onto an outbox row, and only `captured()` ever saves one — for an Order whose Purchase is still to come, because the Verification that reports it runs from the sweep or a gateway's Notification with nobody there to ask, and no Capture row exists to copy from. An Order reporting at Capture keeps nothing (its outbox row already holds the same things), and a Page with no applicable Destination keeps nothing at all — an IP address with no purpose is not collected. `keepFor()` is `firstOrCreate`, so the first visit's word stands.

Which click identifiers are kept is `Provider::clickIdNames()` and nowhere else — `TrackingContext` takes the union across Providers and drops the rest, so adding TikTok adds `ttclid` in the driver alone. They are filtered and truncated, never validated: they arrive in a field no Customer can see, from a script their browser may not have run, and `StoreOrderRequest` carries no rule for them because an Order refused over one is a sale lost to tracking.

## The browser's half is one snippet, built here and never authored in a view
`TrackingSnippet` builds the whole `<script>`: the consent hook, and one `Provider::script()` per Destination from `TrackingPlans::forPage()` — the same answer the recorder takes, so the two halves cannot disagree about where a Page reports or about what it reports there. A Provider's `script()` returns its base code (which reports the page's own load) plus one call per `BrowserEvent`, and returns '' where the Destination has no public identifier; the access token is not asked for, because that is the server half's credential. No Destination means no element, no global and no external request at all.

Both copies of an event carry the order number as the deduplication id (`eventID` on Meta), which is the whole of how one Purchase is counted once. Which Moment an Order's Purchase belongs to is `MomentRecorder::purchaseMomentFor()` and is not restated at a call site.

The consent hook is `TrackingSnippet::CONSENT_HOOK`, one global, consulted once. Absent or true fires; only an explicit `false` holds. Never invert that default — it would silently disable reporting for every Merchant who never heard of it.

## A Page's Pixel is injected in the controller, and a cached Page names no identifier of its own
`TrackingSnippet::injectedInto()` is where a landing Page's Pixel enters the document: `LandingPageController::show()` calls it on the expanded HTML and the one string it returns is both written to the cache and returned, which is how the three-way byte identity in `LandingPageTest` survives injection. Insertion is before `</head>`, then `</body>`, then appended, matched case-insensitively. The authoring preview deliberately gets none — a Merchant looking at their own draft is not a Visit, for the reason the Processing Page carries no Pixel.

A Page's deduplication id cannot be baked in: one file is served to every visitor, so a literal would deduplicate the whole campaign into one Visit. `BrowserEvent::forLoad()` carries JavaScript *naming* the value instead, and every Provider writes it through `deduplicationIdSource()` rather than quoting the string. `Facebook::script()` skips its own bare `PageView` when the events given already include one, so a load is reported once and with that id.

Nothing here is wrapped in a try/catch: the only thing that can fail is reading the Destinations, and the Page lookup above it already made that connection. A catch would trade a loud failure for a cached Page that silently reports nothing until someone saves it.

## One Plan answers where a Page reports and what it reports there
`TrackingPlans::forPage()` (formerly `TrackingDestinations`) answers with a `TrackingPlan`, not a list: the Destinations, the events, and whether the basket travels are all read from one Tracking Config, and the recorder and the snippet builder must not read them separately. `forConfig()` is the same reading for a Config that was never saved — the Tracking tab's preview goes through it rather than restating the rules.

A Page's pick replaces its own Provider's default and leaves other Providers' alone. A pick that has been deleted falls back to the default; a pick that is switched off reports nowhere for that Provider, because pausing is what switching off means.

Two additions to the Provider contract carry this: `loadEvent()`, so a caller can ask whether the load a base code reports by itself is still wanted without ever spelling the name, and `payloadFor()`, so the preview prints exactly what `send()` would send. Content detail is frozen onto the outbox row (`content_detail`) at Moment time — a driver reads the row it is handed, and the Page may be in the bin by the time the sweep runs.

## Checkout Started travels from the island to the snippet, and the money is priced from the Page
The Checkout island raises one DOM event, `CheckoutShortcode::STARTED_EVENT`, on the first touch of a `[data-gio-field]` wrapper, carrying a Selection in its detail (`value`, `count`, `lines` of `{id, quantity, unitPrice}` — the island's own words, in whole currency rather than the minor units the PHP `Selection` counts in). The island knows nothing about tracking and the snippet knows nothing about how a Checkout is drawn; do not make either read the other's markup. `TrackingSnippet::whenCheckoutStarts()` listens, fires each Provider's `calls()` (the tracked events with no base code around them, for a Moment that happens after the load) and posts the beacon with `items=id:quantity,…`. The name lives on `CheckoutShortcode` because the island is what raises it — tracking depends on the Checkout, never the other way round.

The Moment reuses the load's own identifier (`gioLoadId`) as its deduplication id — a Provider counts an event against its own name, so sharing the string with `PageView` is not a collision, it is the two agreeing which load they belong to. That holds only while every Provider deduplicates on event name *and* id; a platform that deduplicates on the id alone would fold the Visit and the Checkout Started into one, and would need the Moment given an identifier of its own.

`Selection` (`App\Support\Tracking\Selection`) is the one domain answer to "what was being bought and what it is worth", frozen onto `tracking_events.selection` where there is no Order. The beacon is trusted for Product ids and counts and for nothing else: `TrackingController::selection()` prices them off the Page, the same doctrine `CheckoutConfig::deliveryFor()` follows. A request naming no items reports `Page::productsOpenWith()`.

An InitiateCheckout's value is what the selected Products come to and carries **no** Delivery Charge, unlike a Purchase's. The Charge is what it costs to bring the parcel rather than part of what was chosen, and it enters a reported value at the Purchase, where the figure has to agree with the Merchant's own Sales. So the reported value is deliberately smaller than the Total the form shows — do not "fix" that by adding the delivery amount; a Page whose Picker opens ticking nothing reports a value of 0 for the same reason, and both copies say so alike.

`browserEventsIn()` keeps the raw call under `source` (`ProviderScript` asks for it; `FacebookScript` supplies it), because an event whose parameters are worked out as it fires has none to decode; `reportsLiveSelection()` and `namesWhatWasBought()` are how a test asks about one. `onLoad($html)` in `tests/Pest.php` is the half of a document that runs as the page loads.

## A Visit is counted as an increment at the beacon, and there is no rollup to protect
`VisitCounter` is the only writer of `visit_counts` (one row per Page per Merchant-timezone day). `MomentRecorder::reportedByBrowser()` calls it before the plan check, so a Page with no Destination and a Page whose `PageView` is switched off both still count — the Merchant's own figure is not an ad platform's to decide.

The switch is `TrackingSettings::$countsVisits`, default off, and exactly three things read it, each for its own question. `VisitCounter::count()` asks whether to write — the only place that decides whether a load is counted. `TrackingSnippet::forPage()` asks whether to carry a beacon at all, which it now does when counting is on **or** a Destination exists. `ReportingQuery::countsVisits()` asks whether there is a figure to give, and answers null rather than zero when there is not (see `.ai/rules/settings.md`). Do not collapse them: they are three questions, not one invariant with two exceptions.

The aggregate is written at report time, not rolled up from `tracking_events`. #103/#118 describe a "rollup before prune" — there is nothing to roll up: rolling up outbox rows would count nothing for a Page with no Destination, would over-count (one row per provider event), and would leave the tile blank until the nightly command ran. `tracking:prune` must simply never touch `visit_counts`.

A count cannot deduplicate (that would mean keeping a row per load, which is the thing it exists not to do), so a beacon that somehow arrives twice is two Visits here and one event at the Provider. Accepted trade.

## Retention is bound to purpose, and prune never touches what is still owed
`TrackingPrune` holds every retention as a constant, never a Setting. Sent outbox rows 7 days, Failed and Expired 30 (the diagnostics screen is what reads them), Tracking Contexts the longest `Provider::acceptanceWindowSeconds()` across Providers — asked of the drivers rather than written down as seven days, so the number and the reason cannot drift and a Provider added later moves it on its own.

Two things must never be deleted. **Nothing Pending**, at any age: a Merchant whose cron has been broken for a week must find the backlog and see all of it go out on the first run after it is fixed, and only the sweep may settle a row. **`visit_counts`**, ever: it is written at report time by `VisitCounter`, so it is already complete before prune runs. #103/#118 call for "rollup before prune"; there is nothing to roll up, and deriving it from the outbox would be wrong as well as late (a Page with no Destination writes no row, and a Page with two provider events per load would double-count). If anything is ever added that a kept figure is derived from, derive it inside `TrackingPrune::run()` before the delete that removes it.

Outbox rows are cut on `updated_at` — the instant the sweep settled them, for all three settled states alike. Contexts on `created_at`, written once at Capture. Deletes are batched at 1000 because a Page under paid traffic writes a row per provider event per load.

`DeliveryHealth` answers the diagnostics screen's four questions and records when the sweep last ran. That marker is written by `TrackingSweep::run()` every time, whatever it found — an empty outbox and a scheduler that never fired look identical from the rows, and telling those apart is the whole point. It lives in the cache (`tracking.swept_at`) because it is operational state, not a domain record; the scheduler already depends on the cache for the sweep's overlap mutex.

## Delivery diagnostics ride on the Destinations screen, and a widget table there needs its own paging key
`DeliveryHealthStats` (header) and `RecentDeliveryFailures` (footer) hang off `ListDestinations`, not the Dashboard: anything under `app/Filament/Widgets` is discovered onto the panel and drawn on the Dashboard, and this is an operator's "is my tracking working" question, asked beside the list that already names an unusable Destination. Both `canView()` on a Destination existing, so a fresh installation is never told its scheduler is broken. They label and never count — the figures are `DeliveryHealth`'s.

`RecentDeliveryFailures` is query-backed (settled rows, possibly thousands), so it needs neither `PaginatesRows` nor its clamp — but it does need `->queryStringIdentifier('failures')` **on the table**. Filament names every table's page plainly `page` otherwise and Livewire reads that off the request, so the Destinations list above would hand its page number down and Filament would draw the *empty state* — a green tick over a list of failures. Note that overriding `getTableQueryStringIdentifier()` on the component does nothing in Filament v4: `InteractsWithTable::makeTable()` never reads it.
