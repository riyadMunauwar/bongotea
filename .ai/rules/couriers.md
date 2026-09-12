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

RedEx implements `book()` by throwing `CourierNotUsable('RedEx cannot be booked from here yet.')` until #130 builds it, so an operator gets a worded refusal and an untouched Order rather than a crash — and never "RedEx refused", because RedEx was never asked. Delete the throw when the real booking lands; do not add a `supportsBooking()` capability. Steadfast (#127) and Pathao (#129) book for real.

## Active is status-plus-abandonment, and a Shipment Event is deduplicated by the database
`Shipment::isActive()` and the `active()` scope are the one reading of Active: the canonical status is not terminal (`ShipmentStatus::isTerminal()` — Delivered, PartlyDelivered, Returned) and `abandoned_at` is null. The third arm the glossary names — "not refused at booking" — has no column, because a synchronous booking records nothing when it is refused (ADR-0020) and so no such row can exist yet; add it when bulk booking (#135) starts writing rows before dispatching them, and change those two places only.

`ShipmentEvent` uniqueness is `unique(shipment_id, deduplication_id)` at the database and never a check at a call site (ADR-0019): RedEx re-imports its whole history on every poll. The id is `ShipmentEvent::deduplicationIdFor(the courier's word, the courier's time)` — a string and not the two columns, because `occurred_at` is null for most of what a Courier says and SQL counts two nulls as distinct. `recordEvent()` inserts and catches `UniqueConstraintViolationException` rather than asking first, and answers with the row already there. The price, accepted: a Courier that gives no times and repeats a status it has reported before (hold, moving, hold) records one event and not two.

Two times per event, both kept: `occurred_at` is the Courier's and is usually null, `recorded_at` is ours and never is. `happenedAt()` is what a timeline draws. Do not read `created_at` as a delivery time.

## The fixture seam is a script per Courier, and `Http::fake()` stacks
`tests/Fixtures/Couriers/CourierScript` is the abstract, one subclass per Courier, driven by the `courier scripts` dataset — the same seam as `GatewayScript` and `ProviderScript`. A Courier's asymmetries are asked of the script (`supportsBulk()`, `batchLimit()`, `rawStatusesFor()`), never branched on by name, so each Courier proves its own vocabulary in its own words. A later Courier is a script and a dataset row, never a new test file.

`Http::fake()` merges stubs and the first callback to answer wins, so a refusal faked before an acceptance answers for both and nothing clears them. `CourierBookingTest::courierAnsweringAfresh()` replaces the client (`app()->forgetInstance(Factory::class)`, `Http::clearResolvedInstances()`, then `preventStrayRequests()` again) — call it before laying a second, different fake in one test.

## A Courier's own parcel questions are `parcelFields()` and the dialog draws nothing it wasn't told
`CourierDriver::parcelFields()` returns `ParcelField`s — the things one Courier needs told about a parcel that no other asks. `BookWithCourierAction` builds the dialog entirely from them and names no courier and no field: one Section per usable Courier that asks anything, keyed `{courier->value}.{field->name}`, `visible()` on the chooser, so hidden couriers' answers are neither validated nor dehydrated. The shared "which store does it leave from" declaration is `ParcelField::pickupStore()`, not a copy in each driver.

`ParcelField::isWorthAsking()` is false for a choice with fewer than two options, and such a field is left out of the schema entirely rather than hidden — that is the whole of "a Merchant with one pickup store is never asked". `CourierBooker::parcelDetails()` still resolves every declared field (`ParcelField::valueFor()`: the User's override where there was a question, the `CourierSettings` default otherwise) and writes the lot onto `shipments.parcel_details`. Drivers read what they send with `Shipment::parcelDetail()` and never off Settings, so what the row says was sent is what was sent.

`CourierBooker::book()` takes a `ParcelDetails`. `CourierBooker::correct()` commits `ParcelDetails::corrections()` in a transaction of its **own**, before the booking's — the one place this class does not hold everything under one lock. A Courier usually refuses a parcel *because* the address was wrong, so a correction rolled back with the refusal is the User's own work discarded at the moment they are rescuing the Order; what they typed is true whatever the Courier says. The booking re-reads the Order under its lock, so the Shipment still snapshots the correction and "at most one Active Shipment" is unaffected. Note the unreachable notification says the correction was kept — keep the two in step.

Two things are deliberately not corrections: the phone (a Customer *is* their number — changing it re-points the Order, which is `EditOrder`'s job) and the delivery note (the Customer's own captured answer; a note typed in the dialog is sent with that parcel only, and clearing the box sends none — `ParcelDetails::noteOr()`).

## Pathao's access token is derived state the driver holds, never a Setting
`PathaoAccessToken` issues Pathao's token from the four credentials in `CourierSettings`, caches it under a sha256 of those credentials, and lets it go 5 minutes before Pathao's own expiry (floor 60s) — so a Merchant pastes no token and a booking never fails on one that aged out overnight. Changing a credential changes the key, so a corrected password takes effect at once. The refresh-token grant is deliberately unused: the password grant is always available, so a refresh path would be a second way to get a token wrong.

A rejected token is never a refused parcel. Both the grant answering and refusing, and a 401 on the booking itself, raise `CourierNotUsable` — never `CourierRefusedBooking`, which would send an operator to ring Pathao about a word only this system issues. `Pathao::read()` calls `$this->token->forget()` on the 401 so the next attempt is signed with a fresh token, and still does not retry inside the call: a booking is asked once while a User waits (ADR-0020), and pressing the button again is the retry, which is what the notification tells them to do.

`PathaoAnswer` reads the envelope (`message`/`type`/`code`/`data`/`errors`) both the grant and the booking arrive in. Steadfast reads its own answers its own way — do not hoist this above the drivers.

Booking omits `recipient_city`, `recipient_zone` and `recipient_area` entirely and sends the free-text address; `PathaoScript::assertBookedFor()` asserts their absence, because a blank or zero added later routes parcels to whatever depot that resolves to. Pathao issues no second tracking code — the Consignment id is both.

RedEx is now the only driver still throwing `CourierNotUsable('… cannot be booked from here yet.')`; the "booking not built yet" test in `CourierBookingTest` names it.

The shared pickup-store key is `ParcelField::PICKUP_STORE` and a driver reading what it sent (`Shipment::parcelDetail()`) must use that constant — a second spelling in a driver sends a blank store rather than failing. A Courier's own fields keep their names on the driver, as Pathao's three do.

`CourierScript::parcelRowFor()` is what a booked attempt's `parcel_details` should equal exactly: the User's answers plus whatever that Courier asks and nobody is shown. It exists because `ParcelField::isWorthAsking()` leaves a one-answer question out of the dialog and still sends it, so Pathao's row holds a `pickupStore` its `parcelDefaults()` does not. Assert with `toBe()` and not a subset — the point is that the row holds no key nobody meant.
