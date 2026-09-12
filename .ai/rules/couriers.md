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

Four exceptions and not one: `CourierRefusedBooking` is the Courier's own answer (final, worded back to the operator), `CourierUnreachable` is silence (nothing concluded, try again), `CourierNotUsable` is a Courier this installation cannot speak to, and `DeliveryAreaNotResolved` is a parcel nobody said where to send. All four extend `BookingNotMade`, so a new one must be caught in `BookWithCourierAction` **above** the `BookingNotMade` fallback or it is worded as "the Courier refused this parcel". The sweep will retry only the silence. `OrderAlreadyShipping` is outside them all — no Courier was asked.

All three couriers book for real: Steadfast (#127), Pathao (#129), RedEx (#130). Do not add a `supportsBooking()` capability.

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

The shared pickup-store key is `ParcelField::PICKUP_STORE` and a driver reading what it sent (`Shipment::parcelDetail()`) must use that constant — a second spelling in a driver sends a blank store rather than failing. A Courier's own fields keep their names on the driver, as Pathao's three do.

`CourierScript::parcelRowFor()` is what a booked attempt's `parcel_details` should equal exactly: the User's answers plus whatever that Courier asks and nobody is shown. It exists because `ParcelField::isWorthAsking()` leaves a one-answer question out of the dialog and still sends it, so Pathao's row holds a `pickupStore` its `parcelDefaults()` does not. Assert with `toBe()` and not a subset — the point is that the row holds no key nobody meant.

## A delivery area is asked of `needsAreaResolution()`, not declared as a `ParcelField`
RedEx alone refuses a free-text address, so the area is its own question and not a `ParcelField`: a `ParcelField` is an override of a per-installation Settings default, and an area belongs to one Order's address. It rides in `ParcelDetails`' `courierFields` under `DeliveryArea::ANSWER`, and `CourierBooker::parcelDetails()` writes `DeliveryArea::asParcelDetails()` (id **and** name, under `DeliveryArea::ANSWER`/`::NAME`) at the head of `parcel_details` whenever the driver says `needsAreaResolution()`. Nothing branches on RedEx.

The id is looked up once, at booking, and both id and name are recorded: a driver that looked the name up again when it sent would send a name the cached list may have changed. Nothing chosen (or an id the list no longer holds) raises `DeliveryAreaNotResolved` before the Courier is asked — a blank area comes back rejected and reads to an operator as "RedEx refused your parcel".

`DeliveryAreaMatcher::guess()` is pure and unit-tested directly (`tests/Unit/DeliveryAreaMatcherTest.php`) — the one sanctioned exception to the top seam. Whole-word names only; a name another matched name spells out in full gives way to it (**containment, never length** — two areas an address names in different breaths are two answers whatever their names are the length of); the District settles a tie between areas sharing a name, and stands in for the address only where the address named nothing at all, and only where it narrows to one. Anything it cannot settle is null: it guesses, it never decides.

`RedExAreas` caches the list for a day under one fixed key — not keyed by the token, unlike `PathaoAccessToken`, because areas are RedEx's geography and not that Merchant's credentials' product. A failed fetch answers `[]` rather than raising — the list is read while the dialog is being drawn, and a screen that will not open is worse than an empty chooser plus a booking that refuses itself in words — and that emptiness is held for **60s**, not a day: RedEx has no bulk endpoint, so an unheld failure is one timed-out list per parcel on a sweep. `DeliveryAreaNotResolved` therefore words an empty chooser and an unanswered question together; do not narrow it to "you did not choose one".

## A faked outage that throws throws for every call, not just the one you meant
`PendingRequest::buildStubHandler()` **invokes every registered stub callback** on every request and then takes the first non-null answer. So a catch-all stub that throws (`fakeOutage()`) throws even for a URL an earlier stub already answered. `RedExScript::fakeOutage()` is therefore one closure that decides both — the area list answers, everything else raises `ConnectionException` — and not an areas stub plus a throwing `'*'`.

RedEx cannot have its dialog drawn until it has said where it delivers, so `RedExScript::makeUsable()` lays the areas stub and every `fake*()` merges it back in (`RedExScript::fake()`), exactly as `PathaoScript` lays the token grant beside everything. `CourierScript::fakeAreas()` is a no-op for the two couriers that read a free-text address, which is what lets `makeEveryCourierUsable()` say "and each of them answers with its areas" without naming RedEx.

`guessedArea()`/`correctedArea()` are the script's answer to "what does your list make of `orderReadyToSend()`'s address, and what would a User correct it to"; `parcelRowFor()` prepends the guess, because the area leads `parcel_details`.
