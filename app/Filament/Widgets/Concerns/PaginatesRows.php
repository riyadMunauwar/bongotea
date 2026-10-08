<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Features\SupportPagination\WithoutUrlPagination;

/**
 * A page of rows out of a report widget's whole result.
 *
 * A table backed by `records()` never paginates itself: the closure's return
 * value is what `InteractsWithTable::getTableRecords()` hands back, so a
 * Collection is drawn whole with no page controls however the table's
 * pagination is configured. Filament draws the paginator only when the closure
 * returns one, built from the `page` and `recordsPerPage` arguments it is
 * given. This is that paginator, in one place, because the three details it
 * has to get right are the three a hand-rolled one forgets:
 *
 * - the **page name** is the table's own (`getTablePaginationPageName()`),
 *   which is why this is a trait rather than a helper: it reads the name off
 *   the widget instead of making every caller pass it;
 * - **`recordsPerPage` can be the string `'all'`**, which Filament's own
 *   query paginator answers by taking the total as the page size;
 * - **`onEachSide(0)`** keeps the link strip narrow enough for a widget,
 *   matching `CanPaginateRecords::paginateTableQuery()`.
 *
 * The rows handed in must already be in their final order. Sorting belongs to
 * the widget — every one today keeps the order `ReportingQuery` hands over,
 * and one that re-sorts on a clicked column does it there — and it has to
 * happen across the whole result before this slices it, or page one would be
 * the top of an unsorted list.
 *
 * A widget reading the Reporting Window pairs this with
 * `updatedPageFilters()`, because Filament resets the table page for its own
 * filters and not for a page filter: without it, narrowing the window from a
 * third page leaves the table asking for a page that no longer exists.
 *
 * **A page past the end is answered with the last page, never with nothing.**
 * Filament's table view draws the empty state on a page holding no rows
 * whatever the total says, so a stale page number does not read as a short
 * table — it reads as no data at all, and on Needs Attention that is a green
 * tick over a backlog nobody has worked. A page number goes stale in more
 * ways than a filter can be reset: the rows shrink between one render and the
 * next, or the number arrives in the query string of a URL that was
 * bookmarked, refreshed or reached with the Back button. Clamping here covers
 * every one of them in one line.
 *
 * **Widget paging stays out of the address bar.** Livewire binds each
 * paginator to a query string key named after the page
 * (`SupportPagination::addUrlHook()`), and every table here is named `page`
 * because none of them declares a query string identifier — so one widget
 * paged to two would put `?page=2` on the Dashboard and hand it to all four
 * on the next visit. `WithoutUrlPagination` drops that binding: which page of
 * a widget a Merchant is on is a moment in a visit, not an address worth
 * keeping.
 */
trait PaginatesRows
{
    use WithoutUrlPagination;

    /**
     * One page of the given rows, as the paginator Filament needs to draw
     * page controls at all.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginateRows(Collection $rows, int|string $page, int|string $recordsPerPage): LengthAwarePaginator
    {
        $total = $rows->count();

        /**
         * `'all'` is every row on one page, and a page size is never zero:
         * `LengthAwarePaginator::lastPage()` divides by it.
         */
        $perPage = max($recordsPerPage === 'all' ? $total : (int) $recordsPerPage, 1);

        $lastPage = max((int) ceil($total / $perPage), 1);

        /** Inside the result, whatever was asked for: see the class note. */
        $currentPage = min(max((int) $page, 1), $lastPage);

        $paginator = new LengthAwarePaginator(
            items: $rows->forPage($currentPage, $perPage)->all(),
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            options: ['pageName' => $this->getTablePaginationPageName()],
        );

        return $paginator->onEachSide(0);
    }
}
