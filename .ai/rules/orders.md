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
