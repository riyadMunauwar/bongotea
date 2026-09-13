---
paths:
  - 'app/Support/Couriers/**'
  - app/Enums/Courier.php
  - app/Enums/ShipmentStatus.php
  - app/Models/Shipment.php
  - app/Models/ShipmentEvent.php
  - app/Filament/Resources/Orders/Actions/BookWithCourierAction.php
  - 'tests/Fixtures/Couriers/**'
  - app/Console/Commands/SweepCouriersCommand.php
---

# Couriers

## The Courier enum is the registry and the driver is the only thing that knows a courier
`Courier` (app/Enums/Courier.php) is the whole registry — adding a fourth courier is one case plus one class implementing `CourierDriver`, with no config list and no provider line (ADR-0013). `config('giopages.couriers.*')` holds only per-courier parcel defaults and is read in `CourierSettings::__construct()` and nowhere else; it never decides which couriers exist.

Nothing outside `app/Support/Couriers/` may branch on a courier's name. Ask the driver: `supportsBulk()`, `supportsWebhook()`, `supportsBalance()`, `needsAreaResolution()`, `batchLimit()`, `reAskIntervalSeconds()`. `batchLimit()` is the one the dispatcher actually cuts on — a Courier with no usable bulk endpoint declares 1 rather than declaring "no bulk", so a batch is the same loop for every courier; `supportsBulk()` is the plain statement of whether one call carries more than one parcel, which the admin and a reader want and no branch does.

"Why this Courier cannot be used" has one wording, in `CourierSettings::refusal()`. `CourierDriver::refusal()` and `Courier::refusal()` are delegations to it — never restate the rule (on, and every credential filled) at a call site. A driver may later add its own reason; it must not re-implement the shared one.

The contract declares only what is implemented. `book()` and `canonicalStatus()` joined it with #127, `track()` with #131, `readAnnouncement()` with #132, `bookAll()` with #135, `balance()` with #137. Do not declare a method every driver would throw from.

## A single booking runs inside the Order's lock, and a refusal records nothing
`CourierBooker::book()` holds `DB::transaction` + `lockForUpdate` on the Order across the driver's HTTP call — deliberately, unlike `PaymentStarter`, which writes the Pending row first and then calls out. "An Order has at most one Active Shipment" is the invariant that stops a Merchant paying for two parcels, and a lock released before the Courier answers does not hold it. Only that Order's row is held, for one driver timeout (Steadfast: 15s).

Nothing is written for an attempt that did not happen: the Shipment is `make()`d unsaved, handed to the driver, and saved only once a Consignment comes back (ADR-0020). So a refused attempt leaves the invoice reference free for the next one — `Shipment::nextInvoiceReference()` counts rows, not tries.

Four exceptions and not one: `CourierRefusedBooking` is the Courier's own answer (final, worded back to the operator), `CourierUnreachable` is silence (nothing concluded, try again), `CourierNotUsable` is a Courier this installation cannot speak to, and `DeliveryAreaNotResolved` is a parcel nobody said where to send. All four extend `BookingNotMade`, so a new one must be caught in `BookWithCourierAction` **above** the `BookingNotMade` fallback or it is worded as "the Courier refused this parcel". The sweep will retry only the silence. `OrderAlreadyShipping` is outside them all — no Courier was asked.

All three couriers book for real: Steadfast (#127), Pathao (#129), RedEx (#130). Do not add a `supportsBooking()` capability.

## Active is status-plus-abandonment, and a Shipment Event is deduplicated by the database
`Shipment::isActive()` and the `active()` scope are the one reading of Active, and they carry all three arms the glossary names: the canonical status is not terminal (`ShipmentStatus::isTerminal()` — Delivered, PartlyDelivered, Returned), `refused_at` is null, and `abandoned_at` is null. The refusal arm exists only for rows a bulk booking wrote before dispatching them — a synchronous booking records nothing when it is refused (ADR-0020), so the only refusals that leave a row behind are the ones `CourierDispatcher` collects. Read Active through those two and nowhere else.

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

## The poll answers rather than throws, and silence writes nothing at all
`CourierDriver::track()` returns a `ShipmentProgress` and never throws — unlike `book()`, which raises one of the four `BookingNotMade`s. A sweep walks every parcel the couriers are carrying, so one that could not be asked about must not unwind the rest.

Three outcomes, mirroring `Delivery` exactly (ADR-0019). `reported()` carries `list<ReportedStatus>` **oldest first, the last being where the parcel stands now** — one entry for Steadfast and Pathao, RedEx's whole history every poll. `refused()` is the Courier answering about *this parcel* and refusing to account for it; `CourierPoller` writes it as a `ShipmentStatus::Unknown` event in the Courier's own words (cut by `ShipmentEvent::asAWord()`, which leaves headroom for the timestamp `deduplicationIdFor()` appends; the full answer is in the log), which is not terminal, so the parcel keeps being followed. `unreachable()` is silence and **writes nothing at all — not even `polled_at`** — which is exactly what makes the Shipment due again on the next sweep; do not "tidy" that by stamping `polled_at`.

`ShipmentProgress::silenceFrom()` is the one reading of "settles nothing": a 5xx, a 401 or a 403. A rejected credential is the installation's and never this parcel's, so it must not reach 200 timelines — Pathao's 401 additionally calls `$this->token->forget()` so the next sweep signs afresh, and the grant's own `CourierUnreachable`/`CourierNotUsable` are caught in `track()` and answered as silence too.

`CourierSweep::poll()` stops a Courier's whole run on the first silence (`return`, not `continue`), as `TrackingSweep::deliver()` does for a Destination: every later parcel would fail the same way and cost a call to find out. That is what caps a broken Courier at one call per sweep.

## The re-ask interval is the driver's; stuck and give-up are the sweep's constants
`couriers:sweep` (scheduled every five minutes in `routes/console.php` beside `payments:sweep` and `tracking:sweep`, `withoutOverlapping`) asks each Courier where its Active Shipments stand. That cadence is only how often the *question* is put: whether a given parcel is due is `CourierDriver::reAskIntervalSeconds()` against `shipments.polled_at` — Steadfast 1800s because it announces nothing, Pathao and RedEx 14400s because they do. Never one global constant.

`CourierSweep::STUCK_AFTER_SECONDS` (a week) and `GIVE_UP_AFTER_SECONDS` (a month) are constants on the class and never Settings (ADR-0020). Both are measured from `booked_at`. Stuck writes `shipments.stuck_at` once, never clears it, and changes nothing else — a stuck parcel is still polled. Giving up only stops the asking: the Shipment stays Active and stays in COD in Flight, because only a User writing it off (Abandoned) may say a parcel is dead.

The sweep never reads or writes an Order (ADR-0018). A Courier with a non-null `refusal()` is passed over whole, before a parcel of its is read, and the refusal is printed verbatim — it already names the Courier.

`Shipment::recordEvent()` is the only writer of timeline rows and is deduplicated by the unique key, so `CourierPoller::ask()` loops the reported list and never asks first.

## A poll is scripted with status words; the script declares the asymmetries
`CourierScript::fakeTracking(string ...$rawStatuses)` scripts one answer to "where is this parcel": a Courier that keeps a history answers with all of them oldest first, one that keeps none answers with the **last** of them. So a test scripts the sequence once and `timelineEntriesFor(...$rawStatuses)` says how many rows that makes (`tracksWholeHistory()` ? count : 1) — never `count()` directly, or Steadfast and Pathao fail.

`reAskIntervalSeconds()`, `tracksWholeHistory()` and `reportsItsOwnTime()` are declared on the script as a **second transcription** of the driver's own constants, for the reason `rawStatusesFor()` is one: read off the driver, "the interval is honoured" would pass against any number, including the one global constant this proves does not exist.

`answeringAfresh()` lives in `tests/Pest.php`, not in a test file, and so do `parcelInFlight()`, `stillMoving()` and `announcementUrl()` — the courier suites are three files now and a helper defined in one is a fatal error for anybody running only another. Call it before laying a second, different fake in one test (`Http::fake()` stacks and the first answering callback wins).

`assertPollsSent()` counts questions about a parcel only, filtered by URL: Pathao's token grant and RedEx's area list are calls to the same host and counting them would make "one parcel was asked about" depend on whether a token or a list happened to still be held.

## A webhook is read for a name and a line of prose, and one class puts the outbound question
`CourierDriver::readAnnouncement(Request)` answers an `Announcement` — our invoice reference, the Courier's *own* claimed status word (logged, never translated, never written) and a line of narration. Steadfast answers null, exactly as it answers `[]` for `deliveryAreas()`; nothing branches on a name.

`CourierPoller::ask()` is the only thing that may move a canonical status from a Courier's word. It was extracted out of `CourierSweep::record()` so that a webhook claiming a change and the sweep put the same question and write the answer down the same way (ADR-0019) — the sweep keeps only the sweeping (what is due, when to give up, the tally) and reads `ShipmentPolled::recorded()` for it. Do not re-implement "ask and write down" at a second call site.

Narration is `Shipment::recordNarration()` and never `recordEvent()` at a call site: it takes the status the Shipment is **already** in, so the row says where the parcel stood and moves nothing — not the status, not `polled_at`, not Active-ness. That is the whole of the bounded exception; keep it in one method.

## A batch is written first and dispatched by the sweep, and only Steadfast takes more than one parcel a call
`BatchBooker::write()` commits a `ShipmentStatus::Written` row per Order under that Order's lock (`CourierBooker::write()`, which shares `readyToSend()` with `book()`) and asks no Courier anything; `CourierSweep` then polls and dispatches, in that order — a parcel handed over this run has nothing to say yet, and dispatching first would poll every batched parcel moments after booking it.

`CourierDispatcher` chunks by `CourierDriver::batchLimit()` and calls `bookAll()`, which **answers rather than throws** (like `track()`, unlike `book()`): a `BatchBooked` carrying accepted and refused keyed by our invoice reference, plus an `error` when the Courier went quiet partway. A parcel the answer names neither way is left Written and offered again next sweep. `place()` and `refuse()` settle each row; a refusal is `refused_at` + `refusal_reason` and never a status change, shaped like Abandoned.

Only Steadfast has a usable bulk endpoint (`/create_order/bulk-order`, 500 a call, a line per invoice, and **no delivery status word** — a bulk-accepted parcel is `Booked` with `raw_status` null). Pathao's bulk endpoint answers with no Consignment per parcel, which would be a batch of real parcels nothing could ever poll, so Pathao declares `supportsBulk()` false and `batchLimit()` 1 and loops through `ParcelsOneAtATime::handedTo()` like RedEx. Do not "restore" Pathao's bulk call without solving the missing Consignment. Note this contradicts an aside in ADR-0020's consequences ("Pathao takes an array"); the decision itself — batch size is the driver's own property — is unchanged.

The delivery area for a batch is guessed once per batch (`ParcelDetails::guessed()` + `DeliveryAreaMatcher::guess()`, the driver's area list fetched once for the whole run); an Order it cannot settle is reported back as needing an area and never written.

## A balance is the driver's answer, held by CourierBalances, and never added to COD in Flight
`CourierDriver::balance()` joined the contract with #137 and is answered by all three — Steadfast for real off `/get_balance` (major units, converted with `Money::toMinorUnits()`), Pathao and RedEx with `CourierBalance::notReported()`, which is documentation and not a gap. It answers rather than throws, like `track()`.

`CourierBalances::of()` is the only caller and reads `supportsBalance()` first, so a Courier that reports none is never asked at all; a `refusal()` is answered ahead of anything held, so a just-switched-off Courier says so rather than showing this morning's figure. The figure is re-read every five minutes (`RedExAreas`' shape) and a read that fails **keeps the figure last read**, carrying its age and what went wrong. It stands for a day measured from **when the Courier gave it** and never from the last attempt to replace it — a failed read that renewed the day would leave a revoked account showing Monday's money in April, and the cache's own TTL cannot bound it because every failed read rewrites the row. Nothing here may ever answer zero for a figure it could not fetch: a zero tells a Merchant their money has been paid over.

The cache key carries a sha256 of that Courier's `credentialProperties()`, as `PathaoAccessToken`'s does: a balance is one account's product, and a Merchant pasting a second account's keys must not be shown the first one's money under the new name.

`CourierMoney` puts that beside COD in Flight, which is `SUM(collect_amount)` over `Shipment::active()` — what the parcel was sent out to collect, so an Order Paid online contributes nothing. The two are never summed and never presented as one number: one is a Courier's word about an account, the other is the Merchant's own Orders.

## Two readings of "this Order may not be sent", and only one is temporary
`CourierBooker::readyToSend()` refuses an Order twice over: `OrderAlreadyShipping` (a parcel is out now — an Order to send later) and `OrderAlreadyDelivered` (`Order::hasArrived()` — finished, and a second parcel is one nobody ordered). Neither is a `BookingNotMade`: no Courier was asked. A Returned Shipment is neither, which is exactly what makes a re-send the answer to it (ADR-0018), and a row a batch booking had refused (`refused_at`) is out of Active, so its Order is re-sendable at once.

Every caller must handle both: `BookWithCourierAction` catches `OrderAlreadyDelivered` above the `BookingNotMade` fallback and hides itself on `hasArrived()`; `BatchBooker` collects them into `BatchWritten::$alreadyDelivered`, which the bulk action names back. `BookBatchWithCourierAction::whatWillBeSent()` counts what will actually go with `Order::notYetSent()` in one query — never two `exists()` per ticked row.

## Abandoning is Shipment::abandon() and writes no status
`Shipment::abandon(User $by)` is the only writer of `abandoned_at`/`abandoned_by`. It touches neither `status` nor `raw_status` nor the timeline — a parcel On Hold and then abandoned says both (ADR-0018) — and an already-abandoned row is left exactly as it was, so a second presser is never credited with somebody else's decision.

It refuses a row that is still `isAwaitingDispatch()`, and that is what makes the missing lock sound rather than a shortcut. Abandoning otherwise only removes a parcel from Active and cannot produce two parcels for one Order; a row the sweep is inside `bookAll()` for is the exception — written off mid-call it would come back holding a real Consignment and be out of Active all the same, and the Order would be offered a second parcel. The refused state is exactly the state `CourierDispatcher::place()` writes, so there is no window between them. Do not "allow cancelling a batch row" here: that is a different capability and Abandoned is said of a parcel a Courier never *collected*.

Reached from the Order (`AbandonShipmentAction` on ViewOrder and EditOrder) and never from `ShipmentResource`, which stays read-only. Screens that draw a written-off parcel must show the Courier's last word beside "Abandoned", never instead of it (`ShipmentInfolist` callout, `ShipmentsTable` status description).

## Courier money lists a Courier that is usable or carrying, and the parcels decide
`CourierMoney::rows()` draws a Courier that `isUsable()`, **or** that appears in `codInFlight()` — one switched off with parcels still out is listed, with the reason there is no balance beside money that is entirely real. A Courier nobody has set up and that is carrying nothing is left out; it used to be listed, and an installation on one Courier read two permanently empty rows forever.

Carrying is tested first on purpose, so Settings can never take money off the table. Test membership with `array_key_exists`, never `> 0`: the grouped query answers only for couriers that have Active Shipments, and a parcel for an Order already Paid online collects nothing at the door and is still a parcel that Courier is carrying.

The invariant this must keep: a Courier holding money is never absent, because an absent row reads as a Courier holding nothing. Widen the rule, never narrow it.

`CourierBalances::of()` still reads `supportsBalance()` before `refusal()`, so a switched-off Courier that publishes no balance says "publishes no balance" and its `error` is null — a test asserting the refusal must ask the script `supportsBalance()` rather than expect it of every Courier.
