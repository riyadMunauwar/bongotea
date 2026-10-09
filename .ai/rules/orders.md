---
paths:
  - 'app/Filament/Resources/Orders/**'
---

# Orders

## The Orders queue opens on New, and the empty state is what pays for it
`ListOrders::getTabs()` is `New · Confirmed · Cancelled · All`, and New leads deliberately: `getDefaultActiveTab()` takes the first key, so the screen opens on the confirmation calls nobody has made yet rather than on `All` like a stock Filament resource. The `status` SelectFilter was removed when the tabs arrived — two controls for one axis can contradict each other (tab Confirmed, filter Cancelled, empty table, no visible cause). Do not add it back.

Queue-first costs two things, and `OrdersTable`'s empty state is the single answer to both. (1) A search from New for an Order somebody confirmed yesterday returns nothing, which reads as "no such order". (2) The payment filters are cross-status: `paid_but_cancelled` is a Cancelled-only question and can never answer from New; `unpaid` spans all three. So when the active tab is not `all` and a search or filter is also narrowing, the heading names the tab and a `showInAllOrders` empty-state action moves `activeTab` to `all` — only that property, so search and filters carry across untouched. Anything cross-status added later gets this for free; `narrowedTabLabel()` is the seam.

The New tab's badge and the sidebar badge both come from `OrderResource::newOrderCount()`. One method on purpose: they are the same number to an operator, 200px apart, and two copies would drift. Both vanish at zero.

Testing: `Livewire::test(ListOrders::class)` now starts on the New tab, so any test whose fixture is Confirmed or Cancelled needs `->set('activeTab', 'all')` first — `OrderPaymentsAdminTest`'s paid-but-cancelled cases do. The empty-state action is a table action: `callTableAction('showInAllOrders')`, not `callAction()`, which resolves only page-level actions and returns null. `Tab::getBadge()` returns a string, so assert `'2'`.

## The queue draws delivery from the Active Shipment, and asks it once per page
`OrdersTable`'s Delivery column is `TextColumn::make('activeShipment.status')` — the Active Shipment and never the newest Shipment, so it blanks again the moment a parcel is over and a Returned Order reads as one to send again. Neither sortable nor searchable on purpose: both would buy a join or an index for a column an operator scans, and the Shipments list is where a parcel is found by name.

**On `EditOrder` that action owes an `after()` that refreshes the form.** The send dialog may carry a correction, and `ParcelDetails::corrections()` commits `customer_name`, `customer_address` and `customer_district` to the **Order** — all three of them fields on `OrderForm`. Livewire's `$data` was filled when the page opened and knows nothing of a write made under it (`$record->refresh()` does not touch it), so without `->after(fn () => $this->refreshFormData([...]))` an operator who fixes a mistyped address in the dialog and then presses Save writes the typo straight back over the correction — while the parcel has already gone with the right one, so the Order and the Shipment disagree silently and the re-send after a return uses the wrong address. Only `EditOrder` needs it: `ViewOrder` draws an infolist and the queue is a table, neither of which holds form state to go stale. Any header action that writes a field the form also holds owes the same hook.

`BookWithCourierAction::make()` sits in `recordActions()` as well as on both Order screens — one action, three screens, one dialog. Its `visible()` asks `Order::isShipping()` per row, so `modifyQueryUsing` eager-loads `activeShipment` beside `withPaidFlag()` and `isShipping()` answers off the loaded relation where there is one; a page of fifty rows is one query, not fifty-one. `CourierBooker::book()` re-reads the Order under its lock with no relations loaded, so the invariant is never decided off a stale relation. Test a row booking with `callTableAction('bookWithCourier', $order, $data)`; `CourierBookingTest::sendWithCourier()` takes `ListOrders::class` as its page and routes there.

`visible()` also asks `! $record->trashed()`. `OrderResource::getEloquentQuery()` drops `SoftDeletingScope`, so a deleted Order opens on all three screens and shows on the queue under `TrashedFilter` — but `CourierBooker::book()` reads the Order back with `Order::query()->lockForUpdate()->findOrFail()`, through the scope still on the model, so without the guard a User fills the whole dialog in and gets a 404. Any action that re-reads an Order outside `getEloquentQuery()` needs the same guard.

"Not yet sent to a courier" (`OrdersTable::NOT_YET_SENT_FILTER` → `Order::notYetSent()`) is a toggle listed in `CROSS_STATUS_FILTERS`, which is all it takes to compose with the tabs and narrow their empty-state labels. Do not make it a fourth tab: the tabs own the `OrderStatus` axis alone.

Its name is a constant rather than a literal because the Dashboard links straight at it: `UnsentOrdersStat` counts the backlog with `OrderResource::notYetSentCount()` and opens it with `OrderResource::notYetSentUrl()`, which writes that name into the query string. Rename the filter through the constant or the link silently lands a User on an unfiltered queue — see "A widget linking into a filtered resource list owes the tab as well as the filter" in `.ai/rules/widgets.md` for the query-string shape.

`notYetSent()` is **not** "no Active Shipment" — every delivered Order satisfies that, and a week-old installation would answer the filter with its whole archive. It is "no Shipment that is Active **or** arrived": `whereDoesntHave('shipments', active OR arrived)`. `ShipmentStatus::isTerminal()` says only that the Courier has stopped and splits two ways here — `isArrival()` (Delivered, PartlyDelivered) finishes an Order, where Returned and Abandoned put it back on the list to send again. Read terminal-ness through those two and never off the status list at a call site.

## Bulk booking is the selection and nothing else — no second status or date control
`BookBatchWithCourierAction` is a `BulkAction` in `OrdersTable::toolbarActions()`, above the delete/restore group. It has one field, the Courier chooser, and deliberately **no status or date pickers of its own**: the tabs and the captured-between filter already express that axis in the Merchant's timezone, and a second control for the same axis could dispatch a set of real parcels that is not the set the User was looking at. The ticked rows are the set.

`modalDescription()` says how many parcels will be booked and how many are skipped for already holding an Active Shipment, before the User commits. The result notification names — never merely counts — the Orders needing a delivery area and the Orders that turned out to be deleted, because a bare number is a search a User cannot make.

A trashed Order can be ticked (`OrderResource::getEloquentQuery()` drops the soft-delete scope), and `CourierBooker::write()` reads the Order back through the scope still on the model, so `BatchBooker` catches `ModelNotFoundException` and reports it rather than letting one deleted row unwind two hundred.

Testing: `Livewire::test(ListOrders::class)->set('activeTab', 'all')->selectTableRecords($ids)->callAction(TestAction::make('bookBatchWithCourier')->table()->bulk(), data: [...])`. `Http::fake()` clears what has been recorded so far, so lay a second fake deliberately when a count is meant to be "since then".

## Order lines are written by Repricing, never by the repeater
OrderForm's `lines` repeater is not bound to the relationship: EditOrder/CreateOrder hand the rows to `App\Support\Orders\Repricing`, which prices them through PriceBook and writes the lines, Delivery Charge and band. EditOrder reprices only when `Repricing::changes()` says the rows differ — untouched lines keep the Capture prices. An Order with any Shipment (`Order::hasAnyShipment()`) has the repeater disabled and `apply()` throws. Override fields are hidden without `Permission::OverridePrices`, so their state is not dehydrated; `apply()` carries every existing override over for such a User rather than reading the absence as removal.

Testing: `fillForm(['lines' => ...])` merges repeater rows (a removed row survives, a default empty row stays on create); use `->set('data.lines', [...])` to replace them.
