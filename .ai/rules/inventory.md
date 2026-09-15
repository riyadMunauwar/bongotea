---
paths:
  - 'app/Support/Inventory/**'
  - app/Models/StockMovement.php
  - app/Models/StockCount.php
  - app/Models/Supplier.php
  - app/Models/Supply.php
  - app/Models/SupplyLine.php
  - app/Enums/StockMovementReason.php
  - app/Enums/AdjustmentCause.php
  - 'app/Filament/Clusters/Inventory/**'
---

# Inventory

## StockLedger is the only writer, and On Hand is never a column
`App\Support\Inventory\StockLedger` writes every Stock Movement and answers every On Hand figure. No application code may create a `StockMovement` anywhere else — a later cause (Return, Loss) is another arm of `reconcile()` here, called from an observer or an action, never rows written at the call site (ADR-0021). `StockLedgerTest` walks `app/` and asserts there is exactly one writer. `StockMovementFactory` writes rows directly and is the one exception: scenery for a screen test, never the system deciding anything. `SupplyFactory` and `SupplyLineFactory` are the same kind of scenery from the other side — a document with no movements behind it, which nothing but a screen test may stand on.

On Hand is the sum of `stock_movements.quantity` and is stored nowhere: read it with `onHand(Product)` for one, or `withOnHand(Builder)` (alias `StockLedger::ON_HAND`) for a list. Both answer **null** for a Product with no movements, and every screen draws that as `—`, never `0` — unknown and none are different facts, like a Courier's Balance. Negative is legal and drawn loudly.

`StockLedger::recordCount()` writes the Stock Count and its Adjustment in one transaction, always exactly one movement per count — including a zero one when the count agrees, which is what also puts a genuinely empty shelf into the ledger. Nobody types a quantity or a difference anywhere.

`StockMovement` and `StockCount` refuse `updating` and `deleting` at the model, and the panel offers no edit or delete route. A mistake is answered by another row.

## Cost enters at a Supply, and the average is derived rather than stored
`StockLedger::recordSupply()` writes the Supply, its lines and one movement per line in one transaction — the movement's source is the **SupplyLine**, never the Supply, or a delivery listing one Product twice would collide on `stock_movements`' one-cause-one-movement unique key.

There is no average-cost column anywhere (ADR-0022). `averageCost()` is Σ(quantity × unit_cost) ÷ Σ(quantity) over that Product's **costed** movements, and it only works because a Supply's movement is stamped with **what was paid** while every other movement is stamped with **the average in force** — units leaving take exactly their share of the value, so the average does not budge. Stamp a Supply with the rolled-forward average instead and the arithmetic breaks silently.

Both halves span **every** movement, including rows with `unit_cost` null (anything written before the first Supply) — a null adds nothing to the money but its units still count. Excluding those units from the divisor was tried and is a trap: a count taken afterwards is measured against On Hand, so uncosted units leave stamped at a costed average, and a Product holding five units reports a value below nothing. Unknown is still not zero — a Product with no costed movement at all has no average, gated on `count(unit_cost)`.

Two more edge rules: where the units come to nothing or less the figure is the last stamped cost, not a division by zero; and `valueAtCost()` is the summed rows themselves rather than On Hand × average — the same number, arrived at from rows a User can be shown, and below nothing only where On Hand is.

Read a list's money figure with `withValueAtCost()` (alias `StockLedger::VALUE_AT_COST`) beside `withOnHand()`, both summed into the one query the list already runs.

## A Supply is voided by exact reversal, stamped at what it paid
`StockLedger::voidSupply()` is the only escape hatch in the ledger, and the only thing that may write to a Supply after it is recorded. It writes one movement per **SupplyLine** — reason `Void`, quantity negated, source the line — then marks the document with `voided_at`/`voided_by`.

The reversal is stamped with **that line's own `unit_cost`**, never the average in force (ADR-0022). Every other movement takes its share of the blend because units really left; these units never arrived, so exactly the money that went in comes back out. Stamp a void at the rolled average instead and a second Supply at a different price leaves the blend permanently wrong.

Cost is then read over the **standing** movements (`StockMovement::standing()`): a Void and the row it reversed are both left out, because between them they are a delivery that did not happen. They come to nothing either way, so no figure moves — except on a Product whose only purchase was the voided one, which goes back to having no known cost at all rather than quoting the price on a delivery just struck out, and whose Value at Cost reads unknown rather than nothing. `withValueAtCost()` carries the same clause so the list and the single figure agree. On Hand deliberately stays the plain sum of every row (ADR-0021): after a void it reads 0, which is what a shelf counted empty reads too.

A reason of its own rather than a negative `Supply` movement: `stock_movements`' unique key is `(source_type, source_id, product_id, reason)`, so a reversal sharing the reason would collide with the row it undoes — and that same key is what makes a second void of one Supply impossible at the database, behind the `lockForUpdate()` re-read and the `LogicException` in front of it.

`Supply::updating` lets through **only** `Supply::VOIDING` (plus the `updated_at` the save itself touches) and still throws on everything else. A second mark of the same Supply is refused three times over — there, in the ledger under its lock, and by the movements' unique key — and the first two raise `SupplyAlreadyVoided` so a screen can tell that case from the refusal to edit. Voiding is offered from `ViewSupply` and never from a row of the list, and any buying figure reads `Supply::notVoided()`; `SuppliersTable`'s Deliveries count is the one that exists today, and `SupplyLedgerTest` asserts the ledger is the only thing in `app/` that writes `voided_at`.

## A parcel moves stock through reconcile(), and the Consignment is what "it left" means
`StockLedger::reconcile(Shipment)` is the only way a parcel ever moves stock, and `ShipmentObserver` (created, plus updated when `status`, `consignment_reference` or `abandoned_at` changed) is the only thing that calls it — never the four courier call sites that write a status (ADR-0021).

What decides that units left is `Shipment::wasHandedOver()`, which is the Consignment and nothing else: Awaiting Dispatch has none, Refused at Booking has none, and a parcel already In Transit or Delivered certainly left. Deliberately not `status === Booked` — the status is where the parcel got to since, not whether it ever went.

Idempotency is the `stock_movements` unique key `(source_type, source_id, product_id, reason)` with the source being the **Shipment**, caught in `writeOnce()` — never an existence check, and never the Order Line as the source: an Order sent twice is two parcels and must deduct twice. That is why `parcelContents()` sums the Order's lines per Product rather than writing one movement per line.

## A parcel's end is read off the movements it wrote, and a write-off is two rows
`reconcile()` has three arms and reads the parcel as it stands rather than the transition that woke it, so the order the tellings arrive in cannot change what the ledger holds: the Sale when it was handed over, a `Return` where `status === Returned`, and an `Unsold` **plus** a `Loss` where `abandoned_at` is set and the status is not terminal.

Both later arms put the units back through one `putBack()` and differ only in the word. The word is the whole point: the unique key is `(source, product, reason)`, so a write-off that put its units back as a `Return` would spend that key — and a parcel written off and *then* announced as Returned (the Announcement reaches a written-off parcel; `couriers:sweep` does not) would have its Return swallowed as a duplicate and leave the units off the shelf for good. `Unsold` also says the truthful thing: nothing came back through a door.

Both later arms work from `departures()` — the Sale movements this Shipment already wrote — and never from the Order. That is what makes them exact three ways over: a Product nobody had counted wrote no Sale and so has nothing to bring back; the quantity is what left however the Order has been edited since; and the cost is the figure **stamped on the Sale**, so a Supply that landed while the parcel was out cannot revalue units on a round trip (ADR-0022). Stamping is now literal — `write()` records exactly the `?int $unitCost` it is handed, null included, and the arm that knows decides it: a Supply line's own cost, `averageCost()` for a departure or a count, the answered row's figure for a Void, a Return or a Loss. Falling back to the average inside `write()` was the old shape and is a trap for reversals: a reversal of an uncosted movement would come back carrying money nothing recorded paying.

Abandoning writes the pair and both rows are meant. The units left the shelf once, at Booked, so a Loss alone would take the same box twice and leave On Hand below the shelf for good — the parcel is taken back off the sale at what it left at and written off from there. On Hand moves by nothing; what the pair adds is the word, and without it a parcel a Courier never collected reads as an ordinary Sale and resurfaces as shrinkage nobody can account for. `ProductStockHistory`'s **Value** column is where that money is read, and it draws nothing where no cost was stamped, like the unit cost beside it.

`CommittedStock` is separate from `StockLedger` on purpose: Committed is a fact about Orders and never a movement. It reads Confirmed + `Order::notYetSent()` and never "no Active Shipment" alone — every delivered Order satisfies that too and would leave the figure climbing forever, which is why the glossary names both halves. Its column draws 0 rather than `—`, because unlike On Hand it is never unknown.
