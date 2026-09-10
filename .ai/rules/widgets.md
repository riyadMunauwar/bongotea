---
paths:
  - 'app/Filament/Widgets/**'
---

# Widgets

## A table backed by records() never paginates itself
`Table::records(...)` replaces the query, and `InteractsWithTable::getTableRecords()` hands back whatever the closure returned. Return a Collection and every row is drawn with no page controls, however the table's pagination is configured — Filament only renders the paginator when the closure itself returns a `Paginator`, built from the `page` and `recordsPerPage` arguments it is given.

So a collection-backed widget table says `->paginated(false)` and means it (PageLeaderboardTable, TopProductsTable, DeliveryMixTable all do). Sorting is the same shape: the closure is handed `$sortColumn` and `$sortDirection` and must sort the rows itself; a `sortable()` column on its own does nothing.
