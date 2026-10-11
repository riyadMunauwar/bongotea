# One account per Provider, held in Settings like a Courier's

The store reports to exactly one account per **Provider**, and what that account needs — for
Facebook, a dataset id and the access token that writes to it — is supplied once in the Tracking
settings page, as a Courier's keys are on the Couriers page. There is no Destination any more: no
table, no resource, no default to choose and no observer keeping exactly one of them. Each Provider
is three properties of `TrackingSettings` by the Courier naming rule (`facebookEnabled`,
`facebookDataset`, `facebookAccessToken`), and an outbox row names the Provider it is for rather
than a Destination.

ADR-0016 rejected a Settings group for this because Destinations were "an unbounded user-created
list where each item carries its own secret", which a group's one flat row cannot express
(ADR-0012). That reasoning still holds and no longer applies: the several-accounts case — separate
brands, separate clients — is not one this store has, and once each Provider has one account the
list is bounded by the `TrackingProvider` enum, which is exactly the shape `CourierSettings`
already holds. A TikTok or Google Ads Provider is then a driver, an enum case and three properties.

**Considered options.** Keeping the `destinations` table at one row per Provider was rejected: it
keeps the foreign key and outbox untouched, but leaves a domain term with nothing to tell it apart
from "the Provider's settings", and an invariant (one per Provider) a model has to be made to
enforce where an enum enforces it for nothing. Keeping several Destinations and only hiding the
list was rejected for the same reason the feature is being removed: the flexibility costs a default
picker, an observer and a resource that no Merchant here uses.

## Consequences

- Switched off writes no outbox rows, as before. Switched on with blank credentials still writes
  them, and the sweep holds them Pending with the Provider's refusal — so "dataset now, token
  later" works and fixing the token later loses nothing.
- Pending rows go to whatever account is configured when the sweep runs, because a row names a
  Provider and not an account. Editing a Destination's dataset already behaved this way.
- The idempotence index is (`provider`, `deduplication_id`, `event`).
- The delivery diagnostics (`DeliveryHealthStats`, `RecentDeliveryFailures`) and each Provider's
  refusal move onto the Tracking settings page, beside the credentials they are about.
- No data was migrated: no installation had reported anything when this was decided, so the
  tracking migration was rewritten in place.
- Going back to several accounts per Provider would mean reintroducing the table, the outbox key and
  the default — ADR-0016's design — rather than widening a settings group.
