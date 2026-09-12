# A courier booking is synchronous when a human waits and swept when nobody does

Booking one Order with a Courier calls the Courier inside the request, the way `PaymentStarter`
calls a gateway: an operator is waiting on the Consignment and the tracking code, often with the
Customer still on the phone, and "queued" is not an answer to what they asked. Booking a batch
writes every Shipment row first and lets `couriers:sweep` dispatch them, the way a Moment writes an
outbox row and `tracking:sweep` delivers it (ADR-0016): nobody is watching a batch of two hundred,
and no web request can survive one.

The codebase holds both patterns already and they look contradictory — payments call out inline,
tracking forbids it outright — but the rule underneath them is one rule, and it is about who is
waiting on the answer. A Customer waits on a gateway redirect, so the call is inline. Nobody waits
on an ad platform, so it is swept. A courier booking is both shapes depending on how many parcels
are in it, so it is written as both.

**Considered options.** Making everything synchronous was rejected outright: RedEx has no bulk
endpoint, so two hundred RedEx parcels is two hundred sequential requests at a fifteen-second
timeout and cannot live in a web request under any circumstances. Making everything swept was
rejected because this installation has no queue worker — the asynchronous mechanism is the
scheduler, so "asynchronous" means up to five minutes, which is fine for a batch and ruinous for an
operator mid-call. A queued job per booking was rejected for the reason ADR-0016 already gives: an
installation with no worker running would fail silently and permanently, which is the failure this
whole design exists to avoid.

## Consequences

- **The outbox earns its place on bulk specifically, and for one reason: partial failure.** A
  connection dropped after a bulk endpoint created five hundred consignments but before the
  response was read leaves five hundred real parcels with no local record, and a re-click creates
  five hundred more that the Merchant pays for. Writing the rows before dispatching is what makes
  that recoverable.
- A batch reports what it will do before it commits — how many will be sent, how many are skipped
  for already holding an Active Shipment — because the set dispatched must be the set the operator
  was looking at.
- Batch size is the driver's own property: Steadfast takes five hundred per call, Pathao takes an
  array, RedEx takes one and is therefore looped. The sweep asks the driver rather than knowing.
- `couriers:sweep` does two jobs — dispatching Shipments a bulk booking wrote, and polling Active
  Shipments for status — and runs every five minutes beside the payment and tracking sweeps, with
  overlap protection. Every interval and give-up horizon is a constant on the sweep class, never a
  Setting, matching how Tracking's retention is held.
- There is no in-request retry anywhere. A refusal is worded back to the operator in the dialog and
  nothing is recorded as sent; an unreachable Courier leaves the Order exactly as it was. The sweep
  is the retry, and only for work that was written down first.
- A single booking that succeeds shows its Consignment and tracking code immediately, which is the
  entire reason it is not swept. If that ever stops being true — a Courier that only ever books
  asynchronously — the split should be revisited rather than the operator left staring at nothing.
