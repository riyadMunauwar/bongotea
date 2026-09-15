---
paths:
  - 'app/Filament/Resources/**'
---

# Resources

## A Section spans one column of a resource form's two-column grid
`CreateRecord`/`EditRecord::defaultForm()` give a resource schema `columns(2)` unless it sets its own, and `CanSpanColumns::getColumnSpan()` defaults every component — Sections included — to a span of 1. So sections pair up two per row rather than stacking full width, which is why OrderForm reads the way it does.

A schema holding a single Section therefore renders it in the left half with the right half blank. Call `columnSpanFull()` there, and give the Section its own `columns()` so the fields keep the grid the page had been giving them. Read the rendered `--col-span-default` if in doubt; this is invisible in the PHP.

Note a Section that sets no `columns()` is **one** column, not two: `HasColumns::getAllColumns()` falls back to `['default' => 1]` for anything that is not a `Schema`, and `Section::setUp()` never sets it. Only a child `Schema` inherits its parent's column count.

## PageForm opts out of that grid, and every tab and section says so
PageForm declares `columns(5)`: `Tabs` takes `columnSpan(4)` and holds Page and Checkout, and Publishing takes the remaining `columnSpan(1)` as a sidebar running the full height beside whichever tab is open. The authoring side is one grid item, which is what keeps Publishing on the first row beside it. If you ever add a second component to that stack, wrap the two in a `Grid::make(1)->columnSpan(4)` rather than dropping it into the schema: CSS auto-flow is sparse and would push the sidebar to the second row instead of backfilling the gap. Note `Grid::make()` defaults to 2 columns, so a stacking Grid must pass `Grid::make(1)` explicitly.

Every Tab and Section there states its own `columns()` even where it matches the default, so the next reader does not have to know Filament's fallback to predict the layout. Keep that up if you add one. Note a `Tab` with no `columns()` inherits its container's count (`Tab::getAllColumns()`), unlike a `Section`, which falls back to one.

## Raise a cross-cutting error on a field that is on screen from every tab
Filament never reveals a hidden component to show a validation error. An inactive `Tab` is Alpine-local state: `tabs.js` moves `tab` on click, on `reset-schema-component-state`, and after a Livewire message only when the active tab has disappeared from the list — never because a field failed. No badge marks a tab whose fields did. A collapsed Section behaves the same way: the collapse state only moves on explicit `expand-section` events, which nothing dispatches on failed validation.

Server validation is unaffected — a field in an unopened tab is still validated, and the error is raised, just nowhere the operator is looking. What concealment does cost is the browser's half: `Tab::canConcealComponents()` returns true for *every* tab, active or not, and a concealed `TextInput`/`Textarea` renders without `required`, `maxlength`, `minlength`, `max` or `min` (see their `toEmbeddedHtml()`). So no field inside a tab gets native browser hints, and `maxLength()` there is a server rule only. Accepted for PageForm; worth knowing before you move a field into a tab and wonder where its length cap went. A Section only conceals when it is `collapsible()` (`Section::canConcealComponents()`), which is why fields in a plain Section keep theirs.

So an error that depends on more than one tab belongs on a field outside them. PageForm's publish-time linter runs on `status`, in the Publishing sidebar, because it reads `body` from the Page tab and `products` from the Checkout tab and answers "why can't I publish this?" — asked at the control that publishes. `Get` resolves against the whole schema, so reading across a tab needs nothing special.

If you ever want `persistCollapsed()` on a Section, give it a `key()` first. A layout Section has no state path, so `HasKey::getKey()` returns blank and `HasId::getId()` returns null — every persisted Section in the panel would then share the single localStorage entry `section--isCollapsed`.

## Never add an index to make a searchable() column faster
Filament builds column search as `LIKE "%term%"` (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php). The leading wildcard means no B-tree index can serve it, so an index on `customer_name`, `customer_phone` or `page_slug` is dead weight: it slows writes and fixes nothing. `orders` is deliberately left without one.

Slow search is fixed by changing the query shape, not the schema — prefix matching (`LIKE 'term%'`), or a fulltext index if MySQL is ever settled on as the deployment target. Note `.env.example` still defaults to SQLite, so fulltext is not currently available.

Decided to leave Orders search unindexed and unchanged: it is one User on one screen, tolerable far longer than the navigation badge's per-request `count()` was. That count is a different case and IS indexed — see `orders_status_created_at_index`, which is equality-tested and therefore index-serviceable.

## `Repeater::getItemState()` validates the row it reads — use `getRawItemState()` in a render-time closure
`Schema::getState()` calls `$this->validate()` (vendor/filament/schemas/src/Concerns/HasState.php), and `Repeater::getItemState()` goes through it. So reading a row's state while the page is being *drawn* — a per-item `deleteAction()`/`visible()` closure, an `itemLabel()`, an extra item action — raises that row's validation failure as an uncaught `ValidationException`, which surfaces as a `ViewException` from `filament/resources/views/pages/page.blade.php` rather than as a form error. It fires on every render once one row is incomplete, so the merchant cannot even reach the box they have to fix.

`getRawItemState()` uses `getStateSnapshot()` and does not validate. The Checkout tab's fields repeater (`Schemas/Checkout/Questions.php`) hides the delete button on the five built-in rows that way, and fills and writes back its per-question Edit modal through the raw state for the same reason. Reach for `getItemState()` only from something that already runs at save time.

## A cast that hands out objects breaks form hydration unless it implements `SerializesCastableAttributes`
`Model::attributesToArray()` leaves a custom cast's value alone unless the cast implements `Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes` or the value itself is `Arrayable` (`HasAttributes::addCastAttributesToArray`). Filament fills an edit form from `attributesToArray()`, then `data_set()`s into that data as it hydrates — so a cast returning a list of objects fails with "Cannot modify readonly property" the moment the record is opened for editing, even when nothing on screen edits the attribute.

`AsCapturedAnswers` implements `serialize()` for exactly that reason: `Order::answers` reads as `list<CapturedAnswer>` in the app and as plain arrays wherever the Order is read as an array, which is also what keeps the activity trail readable. `Page::checkout_config` needs nothing extra because `CheckoutConfig` is `Arrayable`.

Note this is unrelated to whether the attribute is displayed: an infolist entry inside a form (`OrderDocument::answers()`) never dehydrates, but the record's data is hydrated regardless.

## A `TextEntry` holding an array is a list, not a document
`TextEntry` treats an array state as a list of values: `formatStateUsing()` runs once per element and the results are joined, so a raw gateway response (`Payment::start_response`, a JSON column) drawn with `->formatStateUsing(json_encode(...))` comes out as `"FAILED", "Store Credential Error"` — the values, comma-separated, keys gone. To print one JSON document, hand the encoded string to `->state()` instead, and answer null there for a missing value so `->placeholder()` still shows. `OrderDocument::payments()` does this for both raw responses.

## The payment axis is worked from header actions, never from the form
`OrderForm` never holds `payment_method`: it is written by the Retry Page and by the "Switch to Cash on Delivery" header action, and shown as read-only text on both screens (`OrderDocument::paymentMethod()`). Both payment actions (`Orders/Actions/`) sit in the header of `ViewOrder` *and* `EditOrder` so a save after either cannot write a stale method back over it, and so an operator need not open the editor to resolve a Payment. "Check with gateway" goes through `PaymentVerifier` like every other Verification and decides nothing itself; every check logs `payment_checked` (with the resulting state) on the Order's trail, because a still-Pending answer is the case the action exists for and the Verification leaves nothing behind for it — so a settled check leaves two entries, the outcome and the check.

## A new resource must declare $navigationSort or it lands above the Dashboard
Filament reads an undeclared `$navigationSort` as `-1`, not as "last". Every top-level entry in this panel therefore states its own — Dashboard 0, Orders 1, Shipments 2, Products 3, Pages 4, InventoryCluster 10, ReportsCluster 50, SettingsCluster 100 — and a resource added without one jumps to the top of the sidebar silently. `AdminShellTest` asserts the full order, so this fails in CI rather than in front of a Merchant; update it when you add an entry.

The three clusters sit in the tens and hundreds rather than at 5, 6 and 7 so that a category arriving later has somewhere to go without renumbering the five resources above them. A *resource* still takes the next integer.

Pages and resources inside a cluster are numbered separately and are not part of the top-level order — Settings holds General 0, Payment Methods 1, Destinations 2, Tracking 3, Couriers 4; Reports holds Courier Report 0, Stock Report 1, Buying Report 2; Inventory holds Stock Counts 0, Supplies 1, Suppliers 2. A cluster contributes one entry to the sidebar under its own label, so what is inside it never appears in the `AdminShellTest` list.

The full reasoning, including why Pages uses the custom `giopages-funnel` icon rather than `Heroicon::OutlinedFunnel`, is in the AdminPanelProvider rules.

## A resource page needing a control above its widgets writes content() itself
`getHeaderWidgets()` draws *above* the page content, so a screen whose widgets must sit under a control — the Page Report's Reporting Window — cannot use that slot: the widgets would render above the form that governs them. Write `content(Schema $schema)` instead, the way `Filament\Pages\Dashboard` does: `EmbeddedSchema::make('filtersForm')` followed by `Grid::make(2)->schema(fn () => $this->getWidgetsSchemaComponents($this->getWidgets()))`. `HasFiltersForm::bootedHasFiltersForm()` is what caches the `filtersForm` schema the embed names.

A custom resource page's `getTitle()` is the class basename unless overridden, and `getRecordTitle()` answers with the model label because `PageResource` sets no `$recordTitleAttribute`. Read the record's own attribute in `getTitle()` rather than adding that property — it would rename every other page of the resource too.
