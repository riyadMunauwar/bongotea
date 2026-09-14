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
`App\Support\Inventory\StockLedger` writes every Stock Movement and answers every On Hand figure. No application code may create a `StockMovement` anywhere else — a later cause (Supply, Shipment, Return, Loss) is another method here, called from an observer or an action, never rows written at the call site (ADR-0021). `StockLedgerTest` walks `app/` and asserts there is exactly one writer. `StockMovementFactory` writes rows directly and is the one exception: scenery for a screen test, never the system deciding anything. `SupplyFactory` and `SupplyLineFactory` are the same kind of scenery from the other side — a document with no movements behind it, which nothing but a screen test may stand on.

On Hand is the sum of `stock_movements.quantity` and is stored nowhere: read it with `onHand(Product)` for one, or `withOnHand(Builder)` (alias `StockLedger::ON_HAND`) for a list. Both answer **null** for a Product with no movements, and every screen draws that as `—`, never `0` — unknown and none are different facts, like a Courier's Balance. Negative is legal and drawn loudly.

`StockLedger::recordCount()` writes the Stock Count and its Adjustment in one transaction, always exactly one movement per count — including a zero one when the count agrees, which is what also puts a genuinely empty shelf into the ledger. Nobody types a quantity or a difference anywhere.

`StockMovement` and `StockCount` refuse `updating` and `deleting` at the model, and the panel offers no edit or delete route. A mistake is answered by another row.

## Cost enters at a Supply, and the average is derived rather than stored
`StockLedger::recordSupply()` writes the Supply, its lines and one movement per line in one transaction — the movement's source is the **SupplyLine**, never the Supply, or a delivery listing one Product twice would collide on `stock_movements`' one-cause-one-movement unique key.

There is no average-cost column anywhere (ADR-0022). `averageCost()` is Σ(quantity × unit_cost) ÷ Σ(quantity) over that Product's **costed** movements, and it only works because a Supply's movement is stamped with **what was paid** while every other movement is stamped with **the average in force** — units leaving take exactly their share of the value, so the average does not budge. Stamp a Supply with the rolled-forward average instead and the arithmetic breaks silently.

Both halves span **every** movement, including rows with `unit_cost` null (anything written before the first Supply) — a null adds nothing to the money but its units still count. Excluding those units from the divisor was tried and is a trap: a count taken afterwards is measured against On Hand, so uncosted units leave stamped at a costed average, and a Product holding five units reports a value below nothing. Unknown is still not zero — a Product with no costed movement at all has no average, gated on `count(unit_cost)`.

Two more edge rules: where the units come to nothing or less the figure is the last stamped cost, not a division by zero; and `valueAtCost()` is the summed rows themselves rather than On Hand × average — the same number, arrived at from rows a User can be shown, and below nothing only where On Hand is.

Read a list's money figure with `withValueAtCost()` (alias `StockLedger::VALUE_AT_COST`) beside `withOnHand()`, both summed into the one query the list already runs.
