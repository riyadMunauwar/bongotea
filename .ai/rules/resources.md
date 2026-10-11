---
paths:
  - 'app/Filament/Resources/**'
---

# Resources

## A Section spans one column of a resource form's two-column grid
`CreateRecord`/`EditRecord::defaultForm()` give a resource schema `columns(2)` unless it sets its own, and `CanSpanColumns::getColumnSpan()` defaults every component — Sections included — to a span of 1. So sections pair up two per row rather than stacking full width, which is why OrderForm reads the way it does.

A schema holding a single Section therefore renders it in the left half with the right half blank. Call `columnSpanFull()` there, and give the Section its own `columns()` so the fields keep the grid the page had been giving them. Read the rendered `--col-span-default` if in doubt; this is invisible in the PHP.

Note a Section that sets no `columns()` is **one** column, not two: `HasColumns::getAllColumns()` falls back to `['default' => 1]` for anything that is not a `Schema`, and `Section::setUp()` never sets it. Only a child `Schema` inherits its parent's column count.

## Raise a cross-cutting error on a field that is on screen from every tab
Filament never reveals a hidden component to show a validation error. An inactive `Tab` is Alpine-local state: `tabs.js` moves `tab` on click, on `reset-schema-component-state`, and after a Livewire message only when the active tab has disappeared from the list — never because a field failed. No badge marks a tab whose fields did. A collapsed Section behaves the same way: the collapse state only moves on explicit `expand-section` events, which nothing dispatches on failed validation.

Server validation is unaffected — a field in an unopened tab is still validated, and the error is raised, just nowhere the operator is looking. What concealment does cost is the browser's half: `Tab::canConcealComponents()` returns true for *every* tab, active or not, and a concealed `TextInput`/`Textarea` renders without `required`, `maxlength`, `minlength`, `max` or `min` (see their `toEmbeddedHtml()`). So no field inside a tab gets native browser hints, and `maxLength()` there is a server rule only. Worth knowing before you move a field into a tab and wonder where its length cap went. A Section only conceals when it is `collapsible()` (`Section::canConcealComponents()`), which is why fields in a plain Section keep theirs.

So an error that depends on more than one tab belongs on a field outside them, at the control the operator acts on. `Get` resolves against the whole schema, so reading across a tab needs nothing special.

If you ever want `persistCollapsed()` on a Section, give it a `key()` first. A layout Section has no state path, so `HasKey::getKey()` returns blank and `HasId::getId()` returns null — every persisted Section in the panel would then share the single localStorage entry `section--isCollapsed`.

## Never add an index to make a searchable() column faster
Filament builds column search as `LIKE "%term%"` (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php). The leading wildcard means no B-tree index can serve it, so an index on `customer_name` or `customer_phone` is dead weight: it slows writes and fixes nothing. `orders` is deliberately left without one.

Slow search is fixed by changing the query shape, not the schema — prefix matching (`LIKE 'term%'`), or a fulltext index if MySQL is ever settled on as the deployment target. Note `.env.example` still defaults to SQLite, so fulltext is not currently available.

Decided to leave Orders search unindexed and unchanged: it is one User on one screen, tolerable far longer than the navigation badge's per-request `count()` was. That count is a different case and IS indexed — see `orders_status_created_at_index`, which is equality-tested and therefore index-serviceable.

## `Repeater::getItemState()` validates the row it reads — use `getRawItemState()` in a render-time closure
`Schema::getState()` calls `$this->validate()` (vendor/filament/schemas/src/Concerns/HasState.php), and `Repeater::getItemState()` goes through it. So reading a row's state while the page is being *drawn* — a per-item `deleteAction()`/`visible()` closure, an `itemLabel()`, an extra item action — raises that row's validation failure as an uncaught `ValidationException`, which surfaces as a `ViewException` from `filament/resources/views/pages/page.blade.php` rather than as a form error. It fires on every render once one row is incomplete, so the merchant cannot even reach the box they have to fix.

`getRawItemState()` uses `getStateSnapshot()` and does not validate. Reach for `getItemState()` only from something that already runs at save time.

## A cast that hands out objects breaks form hydration unless it implements `SerializesCastableAttributes`
`Model::attributesToArray()` leaves a custom cast's value alone unless the cast implements `Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes` or the value itself is `Arrayable` (`HasAttributes::addCastAttributesToArray`). Filament fills an edit form from `attributesToArray()`, then `data_set()`s into that data as it hydrates — so a cast returning a list of objects fails with "Cannot modify readonly property" the moment the record is opened for editing, even when nothing on screen edits the attribute.

Implement `serialize()` on such a cast so the attribute reads as objects in the app and as plain arrays wherever the model is read as an array, which is also what keeps the activity trail readable. A value object that is `Arrayable` needs nothing extra.

Note this is unrelated to whether the attribute is displayed: an infolist entry inside a form never dehydrates, but the record's data is hydrated regardless.

## A `TextEntry` holding an array is a list, not a document
`TextEntry` treats an array state as a list of values: `formatStateUsing()` runs once per element and the results are joined, so a raw gateway response (`Payment::start_response`, a JSON column) drawn with `->formatStateUsing(json_encode(...))` comes out as `"FAILED", "Store Credential Error"` — the values, comma-separated, keys gone. To print one JSON document, hand the encoded string to `->state()` instead, and answer null there for a missing value so `->placeholder()` still shows. `OrderDocument::payments()` does this for both raw responses.

## The payment axis is worked from header actions, never from the form
`OrderForm` never holds `payment_method`: it is written by the Retry Page and by the "Switch to Cash on Delivery" header action, and shown as read-only text on both screens (`OrderDocument::paymentMethod()`). Both payment actions (`Orders/Actions/`) sit in the header of `ViewOrder` *and* `EditOrder` so a save after either cannot write a stale method back over it, and so an operator need not open the editor to resolve a Payment. "Check with gateway" goes through `PaymentVerifier` like every other Verification and decides nothing itself; every check logs `payment_checked` (with the resulting state) on the Order's trail, because a still-Pending answer is the case the action exists for and the Verification leaves nothing behind for it — so a settled check leaves two entries, the outcome and the check.

## A new resource must declare $navigationSort or it lands above the Dashboard
Filament reads an undeclared `$navigationSort` as `-1`, not as "last". Every top-level entry in this panel therefore states its own — Dashboard 0, Orders 1, Shipments 2, Products 3, Pages 4, Enquiries 5, InventoryCluster 10, ReportsCluster 50, SettingsCluster 100 — and a resource added without one jumps to the top of the sidebar silently. `AdminShellTest` asserts the full order, so this fails in CI rather than in front of a Merchant; update it when you add an entry.

The three clusters sit in the tens and hundreds rather than at 5, 6 and 7 so that a category arriving later has somewhere to go without renumbering the six resources above them. A *resource* still takes the next integer.

Pages and resources inside a cluster are numbered separately and are not part of the top-level order — Settings holds General 0, Store 1, Footer links 2, Delivery Charges 3, Payment Methods 4, Tracking 5, Couriers 6, SMS 7, Inventory 8, Users 9, Roles 10 (`StorefrontSettingsPagesTest` asserts it); Pages holds Header 0, Footer 1, Menus 2 as child items; Reports holds Courier Report 0, Stock Report 1, Buying Report 2; Inventory holds Stock Counts 0, Supplies 1, Suppliers 2. A cluster contributes one entry to the sidebar under its own label, so what is inside it never appears in the `AdminShellTest` list.

The full reasoning, including why Pages uses the custom `giopages-funnel` icon rather than `Heroicon::OutlinedFunnel`, is in the AdminPanelProvider rules.
