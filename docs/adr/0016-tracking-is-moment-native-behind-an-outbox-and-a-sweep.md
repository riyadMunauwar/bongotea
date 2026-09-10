# Tracking is Moment-native, written to an outbox and delivered by a sweep

Tracking is organised around **Moments** — Visit, Checkout Started, Capture, Paid — rather than
around any one ad platform's event names. A **Provider** is one class that translates a Moment
into its own wire format and owns everything platform-specific: which of its events a Moment
produces, how many travel in one request, how long after the fact it still accepts them, why a
**Destination** cannot be delivered to, and the call itself. Adding TikTok or Google Ads is one
class, one enum case and one fixture subclass.

**No web request ever calls a Provider.** A Moment writes one row per applicable Destination to
an outbox and returns; `tracking:sweep`, scheduled beside the payment sweep with overlap
protection, groups those rows by Destination, cuts batches at the Provider's own limit, makes one
request per batch and records the outcome. The states mirror `PaymentState` (ADR-0013): Pending →
Sent | Failed | Expired, and a transport failure or a 5xx is never final — the rows stay Pending
for the next sweep, so a brief outage or a broken cron loses nothing. Only the Provider's own
refusal writes Failed, because that is a defect a human must fix and retrying it would not end.

Exactly one `Purchase` is reported per Order: Capture produces it for a Cash on Delivery Order,
because there is no later moment, and Paid produces it for an online one, because an Order
abandoned at the gateway is not revenue. Idempotence is a unique index on the outbox — Destination,
deduplication id, event name — rather than a check in the three places a Verification runs from.

**Considered options.** A queued job per Moment was rejected: an installation with no worker
running would fail silently and permanently, which is the failure mode this design exists to
avoid. Calling the Provider inline during Capture was rejected because it puts somebody else's
outage on the Customer's conversion path. Modelling tracking on Facebook's own event names was
rejected because the second platform would then have to bend the first one's vocabulary.
Destinations as a Settings group was rejected because a group encrypts per flat property and holds
one row of flat JSON (ADR-0012), which cannot express an unbounded user-created list where each
item carries its own secret.

## Consequences

- The outbox holds **no personal data** — no phone, email or name, hashed or otherwise. What a
  Provider is told about the Customer is built in the sweep from the Order the row points at, so
  the table can be pruned without touching a fulfilment record.
- A Destination is a model, so it does not get a Settings group's cache flush for free. Its
  observer flushes every cached Page on save and delete, because a dataset is baked into the Pixel
  snippet a cached Page carries.
- Exactly one Destination per Provider is default: the first takes it, marking a second unmarks
  the first, and deleting the one holding it passes it on.
- A Destination that is switched off produces no rows at all — that is what a campaign break is.
  One that is on but has blank credentials still accumulates them, and the sweep says why it
  skipped them, so fixing the token later loses nothing.
- Reported value includes the Delivery Charge, matching how `Sales` is defined, so the Merchant's
  Dashboard and their Events Manager never disagree.
- One test body runs against every Provider from a Pest dataset over a fixture script, so a new
  Provider is a subclass and a row rather than a new test file.
