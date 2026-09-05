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

Filament does not auto-expand a collapsed Section holding a validation error — the collapse state is Alpine-local and moves only on explicit `expand-section` events. Checkout is `collapsible()` but starts open for that reason: the body linter's "exactly one product" error is attached to `body`, in a different section from the `products` field that fixes it.

## Never add an index to make a searchable() column faster
Filament builds column search as `LIKE "%term%"` (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php). The leading wildcard means no B-tree index can serve it, so an index on `customer_name`, `customer_phone` or `page_slug` is dead weight: it slows writes and fixes nothing. `orders` is deliberately left without one.

Slow search is fixed by changing the query shape, not the schema — prefix matching (`LIKE 'term%'`), or a fulltext index if MySQL is ever settled on as the deployment target. Note `.env.example` still defaults to SQLite, so fulltext is not currently available.

Decided to leave Orders search unindexed and unchanged: it is one User on one screen, tolerable far longer than the navigation badge's per-request `count()` was. That count is a different case and IS indexed — see `orders_status_created_at_index`, which is equality-tested and therefore index-serviceable.
