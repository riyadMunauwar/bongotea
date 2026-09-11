---
paths:
  - 'app/Filament/Widgets/**'
---

# Widgets

## A table backed by records() never paginates itself
`Table::records(...)` replaces the query, and `InteractsWithTable::getTableRecords()` hands back whatever the closure returned. Return a Collection and every row is drawn with no page controls, however the table's pagination is configured — Filament only renders the paginator when the closure itself returns a `Paginator`, built from the `page` and `recordsPerPage` arguments it is given.

So a collection-backed widget table paginates by returning one: `use PaginatesRows;` and end the closure with `$this->paginateRows($rows, $page, $recordsPerPage)`, declaring `int|string $page, int|string $recordsPerPage` among its arguments. Every report table does (NeedsAttentionTable, PageLeaderboardTable, TopProductsTable, DeliveryMixTable). The trait exists because three details are easy to miss: the page name is the table's own (`getTablePaginationPageName()`), `recordsPerPage` can arrive as the string `'all'`, and `onEachSide(0)` is what keeps the link strip narrow enough for a widget. Ten rows at a time and the `[5, 10, 25, 50]` selector are Filament's own defaults once `->paginated(false)` is gone — `Table::getDefaultPaginationPageOption()` prefers 10 when it is among the options — so neither is configured anywhere.

Sorting is the same shape: the closure is handed `$sortColumn` and `$sortDirection` and must sort the rows itself; a `sortable()` column on its own does nothing. Sort the **whole** result before handing it over, never the page in view — `paginateRows()` cuts the page afterwards, and a sort applied after the cut silently ranks ten rows instead of the leaderboard.

## Every widget table's page number is the same `page`, and a stale one draws the empty state
A table's pagination key is `getTablePaginationPageName()`, which is plain `page` unless the widget declares `getTableQueryStringIdentifier()` — and none do. Livewire then reads that key straight off the request (`SupportPagination::resolvePage()` is `request()->query($alias, $default)`) and, by default, writes it back to the address bar, so `?page=2` left by one widget arrives at all of them on the next visit, refresh or Back. `PaginatesRows` answers both halves: `WithoutUrlPagination` keeps widget paging out of the URL, and `paginateRows()` clamps the requested page to the last page that exists.

The clamp is the load-bearing half, because Filament's table view draws the **empty state** for a page holding no rows however large the total is (`index.blade.php`'s `! count($records)`). An out-of-range page therefore does not look like a short table; on Needs Attention it looks like a green tick over an unworked backlog. Rows shrinking between renders is enough to cause it, so never reach for `resetPage()` alone as the defence.

## A page filter does not reset the table page
Filament calls `resetPage()` for every filter it owns (`HasFilters::updatedTableFilters()`) and for none of the page filters a widget reads through `InteractsWithPageFilters`. So a widget paginating a result that the Reporting Window shortens declares `updatedPageFilters(): void { $this->resetPage(); }`, or narrowing the window from the second page leaves the table asking for a page that no longer exists — an empty table, which a Merchant reads as no Sales. Livewire does fire that hook for a `#[Reactive]` property (`SupportReactiveProps\BaseReactive::hydrate()` queues the update), but a test cannot `->set('pageFilters', ...)` to trigger it: mutating a reactive prop throws `CannotMutateReactivePropException`, so call the hook.

## A report widget is scoped by declaring `record`, never by subclassing
Filament hands a resource page's record to every widget it draws under the key `record` (`InteractsWithRecord::getWidgetData()`, spread by `Page::getWidgetsSchemaComponents()`), whether or not the widget wants it — Livewire's `separateParamsAndAttributes()` is what quietly demotes an unclaimed key to an HTML attribute. So a Dashboard widget is reused on a Page edit screen by adding `use ScopedToPage;` (which declares `public ?Page $record = null`, claiming the key) and passing `$this->record` to the `ReportingQuery` method — every one of them already takes an optional Page. Do not copy a widget to scope it.

A trashed Page still reports: Livewire restores a model property through `Model::newQueryForRestoration()`, which drops the soft-delete scope.
