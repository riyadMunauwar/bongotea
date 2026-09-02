---
paths:
  - 'app/Filament/Resources/**'
---

# Resources

## A Section spans one column of a resource form's two-column grid
`CreateRecord`/`EditRecord::defaultForm()` give a resource schema `columns(2)` unless it sets its own, and `CanSpanColumns::getColumnSpan()` defaults every component — Sections included — to a span of 1. So sections pair up two per row rather than stacking full width, which is why PageForm and OrderForm read the way they do.

A schema holding a single Section therefore renders it in the left half with the right half blank. Call `columnSpanFull()` there, and give the Section its own `columns()` so the fields keep the grid the page had been giving them. Read the rendered `--col-span-default` if in doubt; this is invisible in the PHP.

## Never add an index to make a searchable() column faster
Filament builds column search as `LIKE "%term%"` (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php). The leading wildcard means no B-tree index can serve it, so an index on `customer_name`, `customer_phone` or `page_slug` is dead weight: it slows writes and fixes nothing. `orders` is deliberately left without one.

Slow search is fixed by changing the query shape, not the schema — prefix matching (`LIKE 'term%'`), or a fulltext index if MySQL is ever settled on as the deployment target. Note `.env.example` still defaults to SQLite, so fulltext is not currently available.

Decided to leave Orders search unindexed and unchanged: it is one User on one screen, tolerable far longer than the navigation badge's per-request `count()` was. That count is a different case and IS indexed — see `orders_status_created_at_index`, which is equality-tested and therefore index-serviceable.
