<?php

namespace App\Support\Reporting;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;

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
 * Only the overview is here so far. A later result — a time series, a
 * leaderboard, a Product ranking — is another method returning another
 * result class, built on `ordersIn()`, and no widget changes for it.
 */
final class ReportingQuery
{
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
        [$bucketSql, $bucketBindings] = $this->bucketExpression($buckets);

        $cancelled = OrderStatus::Cancelled->value;
        $new = OrderStatus::New->value;

        $counted = $this->ordersIn($window, $page)
            ->selectRaw(
                "{$bucketSql} as bucket, "
                .'count(*) as order_count, '
                .'sum(case when status != ? then total else 0 end) as sales, '
                .'sum(case when status = ? then 1 else 0 end) as cancelled, '
                .'sum(case when status = ? then 1 else 0 end) as new_orders',
                [...$bucketBindings, $cancelled, $cancelled, $new],
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $unpaid = $this->ordersIn($window, $page)
            ->unpaid()
            ->selectRaw("{$bucketSql} as bucket, count(*) as unpaid", $bucketBindings)
            ->groupBy('bucket')
            ->pluck('unpaid', 'bucket');

        $figures = [];

        foreach (array_keys($buckets) as $index) {
            $row = $counted->get($index);

            $figures[] = new OverviewFigures(
                orders: (int) ($row->order_count ?? 0),
                sales: (int) ($row->sales ?? 0),
                cancelled: (int) ($row->cancelled ?? 0),
                unpaid: (int) ($unpaid->get($index, 0)),
                new: (int) ($row->new_orders ?? 0),
            );
        }

        return $figures;
    }

    /**
     * The Orders captured inside a window, soft-deleted ones already gone
     * through the model's scope, on one Page when asked.
     *
     * @return Builder<Order>
     */
    private function ordersIn(ReportingWindow $window, ?Page $page): Builder
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
