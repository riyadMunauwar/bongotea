---
paths:
  - 'app/Filament/Clusters/Inventory/Resources/Supplies/**'
  - 'app/Filament/Clusters/Inventory/Resources/Suppliers/**'
---

# Supplies

## A Supply is recorded through the ledger, and never edited afterwards
`CreateSupply::handleRecordCreation()` hands the form to `StockLedger::recordSupply()` and writes nothing itself — the same arrangement `AdjustStockAction` uses, and for the same reason: the document and the movements it causes have to be one transaction, with no moment in between for anybody to forget.

The lines Repeater is deliberately **not** `->relationship()`; rows saved by the repeater would be a delivery on the record that the shelf never heard about. The same Product may appear on two lines (no `distinct()`) — a challan naming it twice at two prices is two arrivals of it.

Unit cost is typed in whole currency and dehydrated through `Money::toMinorUnits()`, like `OrderForm`'s delivery charge. It is the only cost anybody types anywhere in the system.

Pages are index/create/view only. `canEdit()`, `canDelete()` and `canDeleteAny()` all answer false even though no edit route exists, because Filament asks those before drawing an action — and `Supply`/`SupplyLine` refuse `updating`/`deleting` at the model too. A wrong Supply is Voided.

`VoidSupplyAction` is a header action on `ViewSupply` and deliberately not a row action on the list: the lines it is about to take back out have to be on the screen the button is on, or a User voids the wrong row of eight deliveries from the same Supplier. It hands the record to `StockLedger::voidSupply()` and writes nothing itself, hides on an already-voided Supply, and catches the ledger's `LogicException` into a warning for the second desk that presses it. The reversal arithmetic is in the Inventory rules.

## A Supplier is the one editable record in Inventory, and is never force-deleted
Everything else here is immutable because it is something that happened; a Supplier is a reference a Supply points at, so a misspelled name or a changed number is corrected in place. Deletion is soft, and `SuppliersTable`/`EditSupplier` deliberately offer no `ForceDeleteAction` — `supplies.supplier_id` is `restrictOnDelete()`, so erasing one who ever delivered anything either fails at the database or takes the buying history with it. The Supply's own `supplier()` relation is `withTrashed()` so a past delivery still reads under their name.

## A Supplier's deliveries have one address, and it filters out the voided ones
`SupplyResource::deliveriesFromUrl(Supplier)` is the only place that address is built. Three screens open it — `SuppliersTable`'s Deliveries count, `EditSupplier`'s header action, and the Buying report's Supplier rows — so a filter key spelled wrong is caught once rather than in three places, and a filter name Filament does not recognise is ignored in silence (the User just lands on the unfiltered list).

It turns on **two** filters, under the `filters` query-string key: `SuppliesTable::SUPPLIER_FILTER` (a `SelectFilter`, state `['value' => id]`) and `SuppliesTable::VOIDED_FILTER` set to the **string** `'false'` (a `TernaryFilter`; Filament only converts `'true'`/`'false'`/`'null'` back to scalars). The second is not decoration: every figure that links here is counted over the deliveries that stand — `SuppliersTable` counts `notVoided()`, and the Buying report excludes a Voided delivery from everything — so without it a Supplier reading "3 deliveries" opens a list of four, with nothing on screen saying why. Count it with the same scope the filter runs. A User looking for what went wrong flips the Voided filter themselves, on the screen, where they can see they have.

## A Supply line is typed in KG or PCS and recorded as grams at a cost per kilogram
Each line has `unit` (SupplyForm::KILOGRAMS/PACKETS; null reads as KG), an optional `pack_id` (required for PCS, limited to that Product's Packs) and a cost per kg or per packet. `SupplyForm::grams()`/`costPerKilogram()` are the one conversion, used by both the live Makes/Line cost figures and `CreateSupply`, so the form shows what the Supply will read; they go through `Grams` (`ofPackets`, `costPerKilogram`). `StockLedger::recordSupply()` takes `pack_id` and itself records `pack_grams` and `packets` (refusing another Product's Pack or a remainder), so a loose line never gets a packet count. The view reads `SupplyLine::packing()` off the recorded figures, never the Pack today.

## A Supply's lines are cards, not a table, so the form works on a phone
`SupplyForm`'s lines Repeater and `SupplyInfolist`'s lines RepeatableEntry are card grids (`->columns()`), not `->table()`: a seven-column table repeater has nowhere to go on a phone, and challans are typed at the door. The form is 2 across on a phone, 4 on md, and 12 on 2xl, where all seven fields share one row (`LINE_COLUMNS`, `LINE_FIELD_SPAN`); the infolist is 2 / 5. Don't put `->table()` back.

Each card is headed by its Product via `itemLabel()`. Filament builds that label from `getStateSnapshot()`, which runs every field's dehydrator on a half-typed line, so every dehydrator on a line must accept null (`unit_cost`'s does) or the page stops drawing. The Total carries `gp-total` (forms.css), which keeps its inline label beside the figure below `sm`.

The merchant label is Purchase (#172): "Add purchase" on the list's create action and as the page title, and "Save purchase" / "Save & add another" on the form buttons.
