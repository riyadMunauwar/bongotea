---
paths:
  - 'app/Filament/Widgets/**'
---

# Widgets

## A table backed by records() never paginates itself
`Table::records(...)` replaces the query, and `InteractsWithTable::getTableRecords()` hands back whatever the closure returned. Return a Collection and every row is drawn with no page controls, however the table's pagination is configured — Filament only renders the paginator when the closure itself returns a `Paginator`, built from the `page` and `recordsPerPage` arguments it is given.

So a collection-backed widget table says `->paginated(false)` and means it (PageLeaderboardTable, TopProductsTable, DeliveryMixTable all do). Sorting is the same shape: the closure is handed `$sortColumn` and `$sortDirection` and must sort the rows itself; a `sortable()` column on its own does nothing.

## A report widget is scoped by declaring `record`, never by subclassing
Filament hands a resource page's record to every widget it draws under the key `record` (`InteractsWithRecord::getWidgetData()`, spread by `Page::getWidgetsSchemaComponents()`), whether or not the widget wants it — Livewire's `separateParamsAndAttributes()` is what quietly demotes an unclaimed key to an HTML attribute. So a Dashboard widget is reused on a Page edit screen by adding `use ScopedToPage;` (which declares `public ?Page $record = null`, claiming the key) and passing `$this->record` to the `ReportingQuery` method — every one of them already takes an optional Page. Do not copy a widget to scope it.

A trashed Page still reports: Livewire restores a model property through `Model::newQueryForRestoration()`, which drops the soft-delete scope.
