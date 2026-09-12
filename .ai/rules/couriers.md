---
paths:
  - 'app/Support/Couriers/**'
  - app/Enums/Courier.php
  - app/Enums/ShipmentStatus.php
  - app/Models/Shipment.php
  - app/Models/ShipmentEvent.php
  - app/Filament/Resources/Orders/Actions/BookWithCourierAction.php
  - 'tests/Fixtures/Couriers/**'
---

# Couriers

## The Courier enum is the registry and the driver is the only thing that knows a courier
`Courier` (app/Enums/Courier.php) is the whole registry — adding a fourth courier is one case plus one class implementing `CourierDriver`, with no config list and no provider line (ADR-0013). `config('giopages.couriers.*')` holds only per-courier parcel defaults and is read in `CourierSettings::__construct()` and nowhere else; it never decides which couriers exist.

Nothing outside `app/Support/Couriers/` may branch on a courier's name. Ask the driver: `supportsBulk()`, `supportsWebhook()`, `supportsBalance()`, `needsAreaResolution()`, `batchLimit()`, `reAskIntervalSeconds()`. RedEx declares a batch limit of 1 rather than "no bulk", so a batch is the same loop for every courier.

"Why this Courier cannot be used" has one wording, in `CourierSettings::refusal()`. `CourierDriver::refusal()` and `Courier::refusal()` are delegations to it — never restate the rule (on, and every credential filled) at a call site. A driver may later add its own reason; it must not re-implement the shared one.

The contract declares only what is implemented. `book()` and `canonicalStatus()` joined it with #127; status polling, webhooks and balance arrive with the tickets that build them. Do not declare a method every driver would throw from.

## A single booking runs inside the Order's lock, and a refusal records nothing
`CourierBooker::book()` holds `DB::transaction` + `lockForUpdate` on the Order across the driver's HTTP call — deliberately, unlike `PaymentStarter`, which writes the Pending row first and then calls out. "An Order has at most one Active Shipment" is the invariant that stops a Merchant paying for two parcels, and a lock released before the Courier answers does not hold it. Only that Order's row is held, for one driver timeout (Steadfast: 15s).

Nothing is written for an attempt that did not happen: the Shipment is `make()`d unsaved, handed to the driver, and saved only once a Consignment comes back (ADR-0020). So a refused attempt leaves the invoice reference free for the next one — `Shipment::nextInvoiceReference()` counts rows, not tries.

Two exceptions, not one: `CourierRefusedBooking` is the Courier's own answer (final, worded back to the operator), `CourierUnreachable` is silence (nothing concluded, try again). Both extend `BookingNotMade`. The sweep will retry only the second. `OrderAlreadyShipping` is neither — no Courier was asked.

Pathao and RedEx implement `book()` by throwing `CourierRefusedBooking('… cannot be booked from here yet.')` until #129/#130 build them, so an operator gets a worded refusal and an untouched Order rather than a crash. Delete the throw when the real booking lands; do not add a `supportsBooking()` capability.

## Active is status-plus-abandonment, and a Shipment Event is deduplicated by the database
`Shipment::isActive()` and the `active()` scope are the one reading of Active: the canonical status is not terminal (`ShipmentStatus::isTerminal()` — Delivered, PartlyDelivered, Returned) and `abandoned_at` is null. The third arm the glossary names — "not refused at booking" — has no column, because a synchronous booking records nothing when it is refused (ADR-0020) and so no such row can exist yet; add it when bulk booking (#135) starts writing rows before dispatching them, and change those two places only.

`ShipmentEvent` uniqueness is `unique(shipment_id, deduplication_id)` at the database and never a check at a call site (ADR-0019): RedEx re-imports its whole history on every poll. The id is `ShipmentEvent::deduplicationIdFor(the courier's word, the courier's time)` — a string and not the two columns, because `occurred_at` is null for most of what a Courier says and SQL counts two nulls as distinct. `recordEvent()` inserts and catches `UniqueConstraintViolationException` rather than asking first, and answers with the row already there. The price, accepted: a Courier that gives no times and repeats a status it has reported before (hold, moving, hold) records one event and not two.

Two times per event, both kept: `occurred_at` is the Courier's and is usually null, `recorded_at` is ours and never is. `happenedAt()` is what a timeline draws. Do not read `created_at` as a delivery time.

## The fixture seam is a script per Courier, and `Http::fake()` stacks
`tests/Fixtures/Couriers/CourierScript` is the abstract, one subclass per Courier, driven by the `courier scripts` dataset — the same seam as `GatewayScript` and `ProviderScript`. A Courier's asymmetries are asked of the script (`supportsBulk()`, `batchLimit()`, `rawStatusesFor()`), never branched on by name, so each Courier proves its own vocabulary in its own words. A later Courier is a script and a dataset row, never a new test file.

`Http::fake()` merges stubs and the first callback to answer wins, so a refusal faked before an acceptance answers for both and nothing clears them. `CourierBookingTest::courierAnsweringAfresh()` replaces the client (`app()->forgetInstance(Factory::class)`, `Http::clearResolvedInstances()`, then `preventStrayRequests()` again) — call it before laying a second, different fake in one test.
