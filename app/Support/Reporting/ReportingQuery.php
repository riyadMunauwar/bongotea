<?php

namespace App\Support\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one seam every report widget calls, and the only place the glossary's
 * counting rules are written in SQL. It takes a Reporting Window and,
 * optionally, a Page to scope to, and hands back plain typed results; a
 * widget formats and draws, and never counts.
 *
 * Everything aggregates in the database. A window is cut into its buckets
 * with one CASE over the bucket boundaries the window itself computed, so
 * the day and hour lines fall at the Merchant's midnight on any driver and
 * in any timezone, daylight saving included, without a timezone table in
 * the database or an offset arithmetic that would be wrong for half the
 * year. Rows are never fetched per Order; the largest thing this module
 * loops over is the list of buckets.
 *
 * The overview, the Sales over time series, the Page leaderboard, the
 * status breakdown and the Payment Method mix are here so far. A later
 * result — a Product ranking, a Delivery mix — is another method returning
 * another result class, built on `ordersIn()`, and no widget changes for it.
 */
final class ReportingQuery
{
    /**
     * Sales as the glossary counts it, in SQL: the totals of the Orders not
     * Cancelled. Binds the Cancelled status once. Written here and nowhere
     * else, so every result sums the same thing.
     */
    private const string SALES = 'sum(case when status != ? then total else 0 end) as sales';

    /**
     * How many Orders hold one status. Binds that status once.
     */
    private const string STATUS_COUNT = 'sum(case when status = ? then 1 else 0 end)';

    /**
     * Orders and Sales per bucket of a window, for the line chart: one
     * grouped query, every bucket in the answer whether or not a row fell
     * inside it.
     */
    public function salesOverTime(ReportingWindow $window, ?Page $page = null): SalesOverTime
    {
        $buckets = $window->buckets();
        $cancelled = OrderStatus::Cancelled->value;

        $rows = $this->groupedByBucket(
            $this->ordersIn($window, $page),
            $buckets,
            'count(*) as order_count, '.self::SALES,
            [$cancelled],
        );

        $points = [];

        foreach ($buckets as $index => $bucket) {
            $row = $rows->get($index);

            $points[] = new SalesOverTimePoint(
                bucket: $bucket,
                orders: (int) ($row->order_count ?? 0),
                sales: (int) ($row->sales ?? 0),
            );
        }

        return new SalesOverTime($window, $points);
    }

    /**
     * The stat tiles' figures for a window, the comparison window's beside
     * them, and the window's own bucket by bucket for the sparklines.
     */
    public function overview(ReportingWindow $window, ?Page $page = null): Overview
    {
        $buckets = $this->figuresByBucket($window, $window->buckets(), $page);

        $figures = array_reduce(
            $buckets,
            fn (OverviewFigures $sum, OverviewFigures $bucket): OverviewFigures => $sum->plus($bucket),
            OverviewFigures::none(),
        );

        $comparison = $window->comparison();
        $whole = new ReportingBucket($comparison->start, $comparison->end);

        return new Overview($window, $figures, $this->figuresByBucket($comparison, [$whole], $page)[0], $buckets);
    }

    /**
     * One row per Page with Orders in the window, trashed Pages included,
     * and one per recorded slug for Orders whose Page is gone for good.
     * One grouped query over the Orders and one lookup of the Pages named;
     * the rows come back Sales first, and the largest thing looped over is
     * the list of Pages.
     */
    public function pageLeaderboard(ReportingWindow $window): PageLeaderboard
    {
        $cancelled = OrderStatus::Cancelled->value;

        /**
         * Grouped by the live link where there is one, and by the recorded
         * slug only where there is not: an Order whose Page was renamed
         * still belongs to that Page, while two hard-deleted Pages that
         * shared nothing but a null are told apart by their slugs.
         */
        $rows = $this->ordersIn($window)
            ->selectRaw(
                'page_id, min(page_slug) as page_slug, count(*) as order_count, '
                .self::SALES.', '
                .self::STATUS_COUNT.' as cancelled',
                [$cancelled, $cancelled],
            )
            ->groupByRaw("page_id, (case when page_id is null then page_slug else '' end)")
            ->get();

        $pages = Page::withTrashed()->findMany($rows->pluck('page_id')->filter())->keyBy(fn (Page $page): int => $page->getKey());

        $leaderboard = $rows
            ->map(fn (object $row): PageLeaderboardRow => new PageLeaderboardRow(
                page: $row->page_id === null ? null : $pages->get((int) $row->page_id),
                slug: (string) $row->page_slug,
                orders: (int) $row->order_count,
                sales: (int) $row->sales,
                cancelled: (int) $row->cancelled,
            ))
            ->sortBy([
                fn (PageLeaderboardRow $a, PageLeaderboardRow $b): int => $b->sales <=> $a->sales,
                fn (PageLeaderboardRow $a, PageLeaderboardRow $b): int => $b->orders <=> $a->orders,
                fn (PageLeaderboardRow $a, PageLeaderboardRow $b): int => strcasecmp($a->title(), $b->title()),
            ])
            ->values()
            ->all();

        return new PageLeaderboard($window, $leaderboard);
    }

    /**
     * How many of the window's Orders are New, Confirmed and Cancelled, for
     * the doughnut: one query, three status counts on one row.
     */
    public function statusBreakdown(ReportingWindow $window, ?Page $page = null): StatusBreakdown
    {
        $row = $this->ordersIn($window, $page)
            ->selectRaw(
                self::STATUS_COUNT.' as new_orders, '
                .self::STATUS_COUNT.' as confirmed, '
                .self::STATUS_COUNT.' as cancelled',
                [OrderStatus::New->value, OrderStatus::Confirmed->value, OrderStatus::Cancelled->value],
            )
            ->first();

        return new StatusBreakdown(
            $window,
            new: (int) ($row->new_orders ?? 0),
            confirmed: (int) ($row->confirmed ?? 0),
            cancelled: (int) ($row->cancelled ?? 0),
        );
    }

    /**
     * Orders and Sales per recorded Payment Method, and each online method's
     * Orders split between Paid and Unpaid, for the bar chart. Two grouped
     * queries: one for what a row says on its own, and one under
     * `Order::unpaid()` — the scope is the rule for Unpaid, and is not
     * restated here as a CASE; the row derives Paid as the rest. The rows
     * come back in the enum's order, and the largest thing looped over is
     * the list of methods.
     *
     * The method column is read under another name so the model's enum cast
     * does not touch it: the rows are keyed by the stored value.
     */
    public function paymentMix(ReportingWindow $window, ?Page $page = null): PaymentMix
    {
        $cancelled = OrderStatus::Cancelled->value;

        $perMethod = $this->ordersIn($window, $page)
            ->selectRaw('payment_method as method, count(*) as order_count, '.self::SALES, [$cancelled])
            ->groupBy('payment_method')
            ->get()
            ->keyBy('method');

        $unpaid = $this->ordersIn($window, $page)
            ->unpaid()
            ->selectRaw('payment_method as method, count(*) as unpaid')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('method');

        $rows = [];

        foreach (PaymentMethod::cases() as $method) {
            $row = $perMethod->get($method->value);

            if ($row === null) {
                continue;
            }

            $rows[] = new PaymentMixRow(
                method: $method,
                orders: (int) $row->order_count,
                sales: (int) $row->sales,
                unpaid: $method->isOnline() ? (int) ($unpaid->get($method->value)?->unpaid ?? 0) : null,
            );
        }

        return new PaymentMix($window, $rows);
    }

    /**
     * The figures for each bucket of a window, in bucket order, an empty
     * bucket as zeros. Two grouped queries: one for what a row says on its
     * own, and one under `Order::unpaid()` — the scope is the rule for
     * Unpaid, and is not restated here as a CASE.
     *
     * @param  list<ReportingBucket>  $buckets
     * @return list<OverviewFigures>
     */
    private function figuresByBucket(ReportingWindow $window, array $buckets, ?Page $page): array
    {
        $cancelled = OrderStatus::Cancelled->value;
        $new = OrderStatus::New->value;

        $counted = $this->groupedByBucket(
            $this->ordersIn($window, $page),
            $buckets,
            'count(*) as order_count, '
            .self::SALES.', '
            .self::STATUS_COUNT.' as cancelled, '
            .self::STATUS_COUNT.' as new_orders',
            [$cancelled, $cancelled, $new],
        );

        $unpaid = $this->groupedByBucket($this->ordersIn($window, $page)->unpaid(), $buckets, 'count(*) as unpaid');

        $figures = [];

        foreach (array_keys($buckets) as $index) {
            $row = $counted->get($index);

            $figures[] = new OverviewFigures(
                orders: (int) ($row->order_count ?? 0),
                sales: (int) ($row->sales ?? 0),
                cancelled: (int) ($row->cancelled ?? 0),
                unpaid: (int) ($unpaid->get($index)?->unpaid ?? 0),
                new: (int) ($row->new_orders ?? 0),
            );
        }

        return $figures;
    }

    /**
     * The aggregates a query comes to in each bucket, keyed by bucket index;
     * a bucket no row fell inside is simply absent, and the caller reads it
     * as zeros. The aggregate columns are the caller's, bound after the
     * bucket boundaries.
     *
     * @param  Builder<Order>  $orders
     * @param  list<ReportingBucket>  $buckets
     * @param  list<string>  $bindings
     * @return Collection<int, object>
     */
    private function groupedByBucket(Builder $orders, array $buckets, string $aggregates, array $bindings = []): Collection
    {
        [$bucketSql, $bucketBindings] = $this->bucketExpression($buckets);

        return $orders
            ->selectRaw("{$bucketSql} as bucket, {$aggregates}", [...$bucketBindings, ...$bindings])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');
    }

    /**
     * The Orders captured inside a window, soft-deleted ones already gone
     * through the model's scope, on one Page when asked.
     *
     * @return Builder<Order>
     */
    private function ordersIn(ReportingWindow $window, ?Page $page = null): Builder
    {
        return Order::query()
            ->where('created_at', '>=', $window->start->toDateTimeString())
            ->where('created_at', '<', $window->end->toDateTimeString())
            ->when($page, fn (Builder $query, Page $page) => $query->where('page_id', $page->getKey()));
    }

    /**
     * A SQL expression reading a row's bucket index from its capture time:
     * a CASE over every boundary but the last, since the rows are already
     * inside the window. One bucket needs no CASE at all.
     *
     * @param  list<ReportingBucket>  $buckets
     * @return array{string, list<string>}
     */
    private function bucketExpression(array $buckets): array
    {
        $last = count($buckets) - 1;

        if ($last === 0) {
            return ['0', []];
        }

        $whens = '';
        $bindings = [];

        foreach (array_slice($buckets, 0, $last) as $index => $bucket) {
            $whens .= " when created_at < ? then {$index}";
            $bindings[] = $bucket->end->toDateTimeString();
        }

        return ["(case{$whens} else {$last} end)", $bindings];
    }
}
