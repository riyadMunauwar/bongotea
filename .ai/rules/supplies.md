---
paths:
  - 'app/Filament/Clusters/Inventory/Resources/Supplies/**'
  - 'app/Filament/Clusters/Inventory/Resources/Suppliers/**'
---

# Supplies

## A Supply is recorded through the ledger, and never edited afterwards
`CreateSupply::handleRecordCreation()` hands the form to `StockLedger::recordSupply()` and writes nothing itself — the same arrangement `CreateStockCount` uses, and for the same reason: the document and the movements it causes have to be one transaction, with no moment in between for anybody to forget.

The lines Repeater is deliberately **not** `->relationship()`; rows saved by the repeater would be a delivery on the record that the shelf never heard about. The same Product may appear on two lines (no `distinct()`) — a challan naming it twice at two prices is two arrivals of it.

Unit cost is typed in whole currency and dehydrated through `Money::toMinorUnits()`, like `OrderForm`'s delivery charge. It is the only cost anybody types anywhere in the system.

Pages are index/create/view only. `canEdit()`, `canDelete()` and `canDeleteAny()` all answer false even though no edit route exists, because Filament asks those before drawing an action — and `Supply`/`SupplyLine` refuse `updating`/`deleting` at the model too. A wrong Supply is Voided.

## A Supplier is the one editable record in Inventory, and is never force-deleted
Everything else here is immutable because it is something that happened; a Supplier is a reference a Supply points at, so a misspelled name or a changed number is corrected in place. Deletion is soft, and `SuppliersTable`/`EditSupplier` deliberately offer no `ForceDeleteAction` — `supplies.supplier_id` is `restrictOnDelete()`, so erasing one who ever delivered anything either fails at the database or takes the buying history with it. The Supply's own `supplier()` relation is `withTrashed()` so a past delivery still reads under their name.
