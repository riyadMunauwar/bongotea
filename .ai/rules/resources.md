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
