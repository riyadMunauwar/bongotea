---
paths:
  - 'app/Filament/Resources/**'
---

# Resources

## A Section spans one column of a resource form's two-column grid
`CreateRecord`/`EditRecord::defaultForm()` give a resource schema `columns(2)` unless it sets its own, and `CanSpanColumns::getColumnSpan()` defaults every component — Sections included — to a span of 1. So sections pair up two per row rather than stacking full width, which is why OrderForm reads the way it does.

A schema holding a single Section therefore renders it in the left half with the right half blank. Call `columnSpanFull()` there, and give the Section its own `columns()` so the fields keep the grid the page had been giving them. Read the rendered `--col-span-default` if in doubt; this is invisible in the PHP.

Note a Section that sets no `columns()` is **one** column, not two: `HasColumns::getAllColumns()` falls back to `['default' => 1]` for anything that is not a `Schema`, and `Section::setUp()` never sets it. Only a child `Schema` inherits its parent's column count.

## PageForm opts out of that grid, and every section says so
PageForm declares `columns(5)`: a `Grid::make(1)->columnSpan(4)` stacks Checkout above Page, and Publishing takes the remaining `columnSpan(1)` as a sidebar running the full height. Wrapping the two in a Grid is what makes Publishing align to the top — placed directly in the grid it would land on the second row, because CSS auto-flow is sparse and never backfills the gap beside Checkout.

Every Section there states its own `columns()` even where it matches the default, so the next reader does not have to know Filament's fallback to predict the layout. Keep that up if you add one. Note `Grid::make()` defaults to 2 columns, so a stacking Grid must pass `Grid::make(1)` explicitly.

## Do not persistCollapsed() a Section holding a field a validation error points at
Filament never auto-expands a collapsed Section to reveal a validation error: the collapse state is Alpine-local (`Section::toEmbeddedHtml()`) and moves only on explicit `expand-section` events, which nothing dispatches on failed validation.

So `collapsible()` is safe — the Section starts open every load — but `persistCollapsed()` is not. It stores the state in localStorage, so "starts open" holds only until the operator collapses it once; after that the Section is shut on arrival forever. Checkout is `collapsible()` alone for exactly this reason: the body linter raises "link exactly one product" on `body`, one section away from the `products` field that fixes it, so a remembered collapse would leave an operator staring at an error whose cause is invisible.

If you ever do want `persistCollapsed()`, give the Section a `key()` first. A layout Section has no state path, so `HasKey::getKey()` returns blank and `HasId::getId()` returns null — every persisted Section in the panel would then share the single localStorage entry `section--isCollapsed`.

## Never add an index to make a searchable() column faster
Filament builds column search as `LIKE "%term%"` (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php). The leading wildcard means no B-tree index can serve it, so an index on `customer_name`, `customer_phone` or `page_slug` is dead weight: it slows writes and fixes nothing. `orders` is deliberately left without one.

Slow search is fixed by changing the query shape, not the schema — prefix matching (`LIKE 'term%'`), or a fulltext index if MySQL is ever settled on as the deployment target. Note `.env.example` still defaults to SQLite, so fulltext is not currently available.

Decided to leave Orders search unindexed and unchanged: it is one User on one screen, tolerable far longer than the navigation badge's per-request `count()` was. That count is a different case and IS indexed — see `orders_status_created_at_index`, which is equality-tested and therefore index-serviceable.
