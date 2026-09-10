---
paths:
  - 'app/Support/Tracking/**'
  - 'app/Models/Destination.php'
  - 'app/Models/TrackingEvent.php'
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

Idempotence is the unique index on (`destination_id`, `deduplication_id`, `event`), not a check at the call site — the recorder uses `firstOrCreate`, so the three places a Verification runs from can each write and only the first lands. Only half the Purchase rule is built so far: `MomentRecorder::captured()` records the Capture Moment for a Cash on Delivery Order and returns early for an online one, and the `Paid` Moment that an online Order needs has no recorder method and no caller yet (#113). `MomentRecorder` is where that rule lives, not the Provider — `eventsFor()` maps both Moments to `Purchase` and knows nothing about how an Order is paid. The outbox row holds **no personal data**: `Facebook::payloadFor()` reads the Customer off `$event->order` at delivery, which is what lets the table be pruned without touching a fulfilment record. Match keys are SHA-256 hashed after Meta's normalisation; `client_ip_address`, `client_user_agent`, `fbc` and `fbp` must stay plain, because a hashed one matches nobody.

Which Destinations apply is `TrackingDestinations::forPage()` and nowhere else. A Destination that is switched off yields no rows at all (that is a campaign break); one that is on with blank credentials still accumulates them and the sweep prints the Provider's refusal. Why a Destination is unusable has one wording, `Provider::refusal()` — the sweep, the table column and any later screen ask it rather than restating the rule.

`DestinationObserver` does two things a model does not get for free: keeps exactly one default per Provider (first one takes it, marking a second unmarks the first, deleting the holder passes it on), and calls `PageCacheInvalidator::forgetEveryPage()` on save and delete, because the dataset is baked into every cached Page's Pixel snippet.

Tests run as a Pest dataset over `tests/Fixtures/Tracking/ProviderScript`; a new Provider is a subclass plus a row in `tests/Datasets/TrackingProviders.php`, never a new test file. `Http::fake()` stubs stack with the first answering callback winning, so recovering from `fakeOutage()` flips a flag inside that one fake (`endOutage()`) rather than laying a second fake.
