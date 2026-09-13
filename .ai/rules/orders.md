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

`BookWithCourierAction::make()` sits in `recordActions()` as well as on both Order screens — one action, three screens, one dialog. Its `visible()` asks `Order::isShipping()` per row, so `modifyQueryUsing` eager-loads `activeShipment` beside `withPaidFlag()` and `isShipping()` answers off the loaded relation where there is one; a page of fifty rows is one query, not fifty-one. `CourierBooker::book()` re-reads the Order under its lock with no relations loaded, so the invariant is never decided off a stale relation. Test a row booking with `callTableAction('bookWithCourier', $order, $data)`; `CourierBookingTest::sendWithCourier()` takes `ListOrders::class` as its page and routes there.

`visible()` also asks `! $record->trashed()`. `OrderResource::getEloquentQuery()` drops `SoftDeletingScope`, so a deleted Order opens on all three screens and shows on the queue under `TrashedFilter` — but `CourierBooker::book()` reads the Order back with `Order::query()->lockForUpdate()->findOrFail()`, through the scope still on the model, so without the guard a User fills the whole dialog in and gets a 404. Any action that re-reads an Order outside `getEloquentQuery()` needs the same guard.

"Not yet sent to a courier" (`not_yet_sent` → `Order::notYetSent()`) is a toggle listed in `CROSS_STATUS_FILTERS`, which is all it takes to compose with the tabs and narrow their empty-state labels. Do not make it a fourth tab: the tabs own the `OrderStatus` axis alone.

`notYetSent()` is **not** "no Active Shipment" — every delivered Order satisfies that, and a week-old installation would answer the filter with its whole archive. It is "no Shipment that is Active **or** arrived": `whereDoesntHave('shipments', active OR arrived)`. `ShipmentStatus::isTerminal()` says only that the Courier has stopped and splits two ways here — `isArrival()` (Delivered, PartlyDelivered) finishes an Order, where Returned and Abandoned put it back on the list to send again. Read terminal-ness through those two and never off the status list at a call site.
