---
paths:
  - 'app/Support/Inventory/**'
  - app/Models/StockMovement.php
  - app/Models/StockCount.php
  - app/Enums/StockMovementReason.php
  - app/Enums/AdjustmentCause.php
  - 'app/Filament/Clusters/Inventory/**'
---

# Inventory

## StockLedger is the only writer, and On Hand is never a column
`App\Support\Inventory\StockLedger` writes every Stock Movement and answers every On Hand figure. No application code may create a `StockMovement` anywhere else — a later cause (Supply, Shipment, Return, Loss) is another method here, called from an observer or an action, never rows written at the call site (ADR-0021). `StockLedgerTest` walks `app/` and asserts there is exactly one writer. `StockMovementFactory` writes rows directly and is the one exception: scenery for a screen test, never the system deciding anything.

On Hand is the sum of `stock_movements.quantity` and is stored nowhere: read it with `onHand(Product)` for one, or `withOnHand(Builder)` (alias `StockLedger::ON_HAND`) for a list. Both answer **null** for a Product with no movements, and every screen draws that as `—`, never `0` — unknown and none are different facts, like a Courier's Balance. Negative is legal and drawn loudly.

`StockLedger::recordCount()` writes the Stock Count and its Adjustment in one transaction, always exactly one movement per count — including a zero one when the count agrees, which is what also puts a genuinely empty shelf into the ledger. Nobody types a quantity or a difference anywhere.

`StockMovement` and `StockCount` refuse `updating` and `deleting` at the model, and the panel offers no edit or delete route. A mistake is answered by another row.
