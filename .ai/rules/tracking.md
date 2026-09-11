---
paths:
  - 'app/Support/Tracking/**'
  - 'app/Models/Destination.php'
  - 'app/Models/TrackingEvent.php'
  - 'app/Models/TrackingContext.php'
  - 'app/Observers/DestinationObserver.php'
  - 'app/Enums/Tracking*.php'
  - 'app/Console/Commands/SweepTrackingCommand.php'
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

Which Destinations apply is `TrackingDestinations::forPage()` and nowhere else. A Destination that is switched off yields no rows at all (that is a campaign break); one that is on with blank credentials still accumulates them and the sweep prints the Provider's refusal. Why a Destination is unusable has one wording, `Provider::refusal()` — the sweep, the table column and any later screen ask it rather than restating the rule.

`DestinationObserver` does two things a model does not get for free: keeps exactly one default per Provider (first one takes it, marking a second unmarks the first, deleting the holder passes it on), and calls `PageCacheInvalidator::forgetEveryPage()` on save and delete, because the dataset is baked into every cached Page's Pixel snippet.

Tests run as a Pest dataset over `tests/Fixtures/Tracking/ProviderScript`; a new Provider is a subclass plus a row in `tests/Datasets/TrackingProviders.php`, never a new test file. `Http::fake()` stubs stack with the first answering callback winning, so recovering from `fakeOutage()` flips a flag inside that one fake (`endOutage()`) rather than laying a second fake.

## A Tracking Context is kept only where the Moment that needs it has no browser
`App\Models\TrackingContext` is both the row and the thing passed around: `none()` and `fromRequest()` build one unsaved, `MomentRecorder::write()` copies it onto an outbox row, and only `captured()` ever saves one — for an Order whose Purchase is still to come, because the Verification that reports it runs from the sweep or a gateway's Notification with nobody there to ask, and no Capture row exists to copy from. An Order reporting at Capture keeps nothing (its outbox row already holds the same things), and a Page with no applicable Destination keeps nothing at all — an IP address with no purpose is not collected. `keepFor()` is `firstOrCreate`, so the first visit's word stands.

Which click identifiers are kept is `Provider::clickIdNames()` and nowhere else — `TrackingContext` takes the union across Providers and drops the rest, so adding TikTok adds `ttclid` in the driver alone. They are filtered and truncated, never validated: they arrive in a field no Customer can see, from a script their browser may not have run, and `StoreOrderRequest` carries no rule for them because an Order refused over one is a sale lost to tracking.

## The browser's half is one snippet, built here and never authored in a view
`TrackingSnippet` builds the whole `<script>`: the consent hook, and one `Provider::script()` per Destination from `TrackingDestinations::forPage()` — the same answer the recorder takes, so the two halves cannot disagree about where a Page reports. A Provider's `script()` returns its base code (which reports the page's own load) plus one call per `BrowserEvent`, and returns '' where the Destination has no public identifier; the access token is not asked for, because that is the server half's credential. No Destination means no element, no global and no external request at all.

Both copies of an event carry the order number as the deduplication id (`eventID` on Meta), which is the whole of how one Purchase is counted once. Which Moment an Order's Purchase belongs to is `MomentRecorder::purchaseMomentFor()` and is not restated at a call site.

The consent hook is `TrackingSnippet::CONSENT_HOOK`, one global, consulted once. Absent or true fires; only an explicit `false` holds. Never invert that default — it would silently disable reporting for every Merchant who never heard of it.

## A Page's Pixel is injected in the controller, and a cached Page names no identifier of its own
`TrackingSnippet::injectedInto()` is where a landing Page's Pixel enters the document: `LandingPageController::show()` calls it on the expanded HTML and the one string it returns is both written to the cache and returned, which is how the three-way byte identity in `LandingPageTest` survives injection. Insertion is before `</head>`, then `</body>`, then appended, matched case-insensitively. The authoring preview deliberately gets none — a Merchant looking at their own draft is not a Visit, for the reason the Processing Page carries no Pixel.

A Page's deduplication id cannot be baked in: one file is served to every visitor, so a literal would deduplicate the whole campaign into one Visit. `BrowserEvent::forLoad()` carries JavaScript *naming* the value instead, and every Provider writes it through `deduplicationIdSource()` rather than quoting the string. `Facebook::script()` skips its own bare `PageView` when the events given already include one, so a load is reported once and with that id.

Nothing here is wrapped in a try/catch: the only thing that can fail is reading the Destinations, and the Page lookup above it already made that connection. A catch would trade a loud failure for a cached Page that silently reports nothing until someone saves it.
