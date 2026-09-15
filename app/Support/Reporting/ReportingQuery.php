<?php

namespace App\Support\Reporting;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Page;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\VisitCount;
use App\Support\Settings\TrackingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
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
 * Orders are not the only rows it reads: Visits come from the aggregate the
 * beacon writes, which is a count per Page per day rather than a row per
 * load. That one is compared as whole days in the Merchant's timezone, since
 * that is what it is keyed by — never as the UTC instants every Order figure
 * beside it is cut on — and it answers null rather than zero where the
 * Merchant is not counting, which is how a screen knows to say the feature is
 * off instead of drawing a silent nought.
 *
 * Shipments are read the same way and cut on their own instant: a parcel
 * belongs to the window the Courier accepted it in, not the one its Order was
 * captured in, because what a Courier is being judged on is the mornings it
 * was handed parcels.
 *
 * The overview, the Sales over time series, the Page leaderboard, the
 * status breakdown, the Payment Method mix, the Product ranking, the
 * Delivery mix, the courier performance and the Needs Attention list are
 * here. A later result is another method returning another result class,
 * built on `ordersIn()`, `notCancelledIn()` or `parcelsSentIn()`, and no
 * widget changes for it. Needs Attention is the one result that takes no
 * window: it is a to-do list over the whole installation.
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
     * How many Districts the Delivery mix names. Five is what fits beside
     * the Delivery Options in a half-width widget, and what the spec asked
     * for; a Merchant who wants the whole list is owed a Districts report,
     * not a longer footnote.
     */
    private const int DISTRICTS_LISTED = 5;

    /**
     * What a duration read off two stored instants is divided by to become
     * the days a Merchant judges a Courier's speed in.
     */
    private const int SECONDS_A_DAY = 86_400;

    /**
     * How long a New Order may wait for its confirmation call before it
     * needs attention. A constant rather than a Setting until a Merchant
     * asks for it to move.
     */
    public const int NEW_ORDER_ATTENTION_AFTER_HOURS = 24;

    /**
     * How long a Payment may sit Pending before the gateway should be asked
     * about it. A constant, like the one above, for the same reason.
     */
    public const int PENDING_PAYMENT_ATTENTION_AFTER_MINUTES = 60;

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

        /**
         * Visits are attached to the two whole windows and to no bucket: the
         * aggregate counts a day, and a one-day window's buckets are hours.
         */
        return new Overview(
            $window,
            $figures->withVisits($this->visitsIn($window, $page)),
            $this->figuresByBucket($comparison, [$whole], $page)[0]->withVisits($this->visitsIn($comparison, $page)),
            $buckets,
        );
    }

    /**
     * One row per Page with Orders in the window, trashed Pages included,
     * and one per recorded slug for Orders whose Page is gone for good.
     * One grouped query over the Orders, one over the Visit aggregate where
     * the Merchant counts Visits, and one lookup of the Pages named; the rows
     * come back Sales first, and the largest thing looped over is the list of
     * Pages.
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
        $visits = $this->visitsByPageIn($window);

        $leaderboard = $rows
            ->map(fn (object $row): PageLeaderboardRow => new PageLeaderboardRow(
                page: $row->page_id === null ? null : $pages->get((int) $row->page_id),
                slug: (string) $row->page_slug,
                orders: (int) $row->order_count,
                sales: (int) $row->sales,
                cancelled: (int) $row->cancelled,

                /**
                 * A Page deleted for good keeps no counts and takes none: its
                 * Orders sit here under a recorded slug, and a slug is not
                 * something a load was ever counted against.
                 */
                visits: $visits === null || $row->page_id === null ? null : ($visits[(int) $row->page_id] ?? 0),
            ))
            ->sortBy([
                fn (PageLeaderboardRow $a, PageLeaderboardRow $b): int => $b->sales <=> $a->sales,
                fn (PageLeaderboardRow $a, PageLeaderboardRow $b): int => $b->orders <=> $a->orders,
                fn (PageLeaderboardRow $a, PageLeaderboardRow $b): int => strcasecmp($a->title(), $b->title()),
            ])
            ->values()
            ->all();

        return new PageLeaderboard($window, $visits !== null, $leaderboard);
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
     * Units and revenue per Product sold in the window, highest revenue
     * first: one grouped query over the Order Lines of the Orders that
     * count, and one lookup of the Products named. A Cancelled Order sold
     * nothing, so its Lines are not here.
     *
     * Grouped by the live link where there is one, and by the recorded name
     * only where there is not: a Product renamed after it sold still merges
     * into one row, while two Products deleted for good are told apart by
     * the names their Lines recorded. The largest thing looped over is the
     * list of Products.
     */
    public function productRanking(ReportingWindow $window, ?Page $page = null): ProductRanking
    {
        $rows = OrderLine::query()
            ->joinSub($this->notCancelledIn($window, $page)->select('id'), 'counted', 'counted.id', '=', 'order_lines.order_id')
            ->selectRaw('product_id, min(product_name) as product_name, sum(quantity) as units, sum(line_total) as revenue')
            ->groupByRaw("product_id, (case when product_id is null then product_name else '' end)")
            ->get();

        $products = Product::withTrashed()
            ->findMany($rows->pluck('product_id')->filter())
            ->keyBy(fn (Product $product): int => $product->getKey());

        $ranking = $rows
            ->map(fn (object $row): ProductRankingRow => new ProductRankingRow(
                product: $row->product_id === null ? null : $products->get((int) $row->product_id),
                recordedName: (string) $row->product_name,
                units: (int) $row->units,
                revenue: (int) $row->revenue,
            ))
            ->sortBy([
                fn (ProductRankingRow $a, ProductRankingRow $b): int => $b->revenue <=> $a->revenue,
                fn (ProductRankingRow $a, ProductRankingRow $b): int => $b->units <=> $a->units,
                fn (ProductRankingRow $a, ProductRankingRow $b): int => strcasecmp($a->name(), $b->name()),
            ])
            ->values()
            ->all();

        return new ProductRanking($window, $ranking);
    }

    /**
     * What each Delivery Option collected in the window and where the
     * Orders were bound: two grouped queries over the Orders that count,
     * the second stopping at the five Districts named most often.
     *
     * A label is normalised to a blank in SQL so a null and an empty string
     * are one row rather than two, and read back as null; a blank District
     * is left out altogether, because "no District" is not a place a
     * courier covers.
     */
    public function deliveryMix(ReportingWindow $window, ?Page $page = null): DeliveryMix
    {
        $rows = $this->notCancelledIn($window, $page)
            ->selectRaw("coalesce(delivery_label, '') as label, count(*) as order_count, sum(delivery_charge) as delivery_charge")
            ->groupBy('label')
            ->get()
            ->map(fn (object $row): DeliveryMixRow => new DeliveryMixRow(
                label: $row->label === '' ? null : (string) $row->label,
                orders: (int) $row->order_count,
                deliveryCharge: (int) $row->delivery_charge,
            ))
            ->sortBy([
                fn (DeliveryMixRow $a, DeliveryMixRow $b): int => $b->orders <=> $a->orders,
                fn (DeliveryMixRow $a, DeliveryMixRow $b): int => $b->deliveryCharge <=> $a->deliveryCharge,
                fn (DeliveryMixRow $a, DeliveryMixRow $b): int => strcasecmp((string) $a->label, (string) $b->label),
            ])
            ->values()
            ->all();

        $districts = $this->notCancelledIn($window, $page)
            ->whereNotNull('customer_district')
            ->where('customer_district', '!=', '')
            ->selectRaw('customer_district as district, count(*) as order_count')
            ->groupBy('district')
            ->orderByDesc('order_count')
            ->orderBy('district')
            ->limit(self::DISTRICTS_LISTED)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->district => (int) $row->order_count])
            ->all();

        return new DeliveryMix($window, $rows, $districts);
    }

    /**
     * How each Courier actually performed over a window: one row per Courier
     * and, deliberately, no row that is all of them (ADR-0018).
     *
     * Two queries over the parcels the couriers accepted inside the window.
     * The first counts, in SQL, everything that is a count — parcels sent,
     * the ones that arrived, the ones that came back, and what the Courier
     * has said the carriage cost. The second is the one thing in this module
     * read row by row rather than aggregated, because arithmetic over two
     * instants is not spelled the same way on every driver this runs on, and
     * the bucket trick every other figure leans on cannot stand in for it: a
     * duration is not a boundary to fall one side of. What it loads is the
     * window's arrived parcels and two columns of each.
     *
     * Neither query restates a rule that lives elsewhere. **Sent** is a
     * `booked_at` inside the window, which is the one instant a Courier
     * accepted the parcel. **Arrived** is `Shipment::arrived()`, the same
     * scope that stops a delivered Order reading as one nobody ever sent.
     * And **when a parcel arrived** is the row's `updated_at`, on the reading
     * `needsAttention()` already takes of a returned one: a terminal attempt
     * is never polled again, so the last write to the row is the outcome
     * being written.
     */
    public function courierPerformance(ReportingWindow $window): CourierPerformance
    {
        $arrivals = ShipmentStatus::arrivalValues();
        $arrived = implode(', ', array_fill(0, count($arrivals), '?'));

        /** The Courier column is read under another name so the model's enum cast does not touch it, exactly as `paymentMix()` reads its method: the rows are keyed by the stored value. */
        $counts = $this->parcelsSentIn($window)
            ->selectRaw(
                'courier as carrier, count(*) as sent, '
                ."sum(case when status in ({$arrived}) then 1 else 0 end) as delivered, "
                .'sum(case when status = ? then 1 else 0 end) as came_back, '

                /**
                 * The charge, and beside it how many parcels carry one at
                 * all: a Courier that has named none is owed a null rather
                 * than the nought this sum comes to, because a zero in a
                 * money column reads as carriage that cost nothing.
                 */
                .'sum(case when courier_charge is null then 0 else courier_charge end) as charges, '
                .'sum(case when courier_charge is null then 0 else 1 end) as charges_named',
                [...$arrivals, ShipmentStatus::Returned->value],
            )
            ->groupBy('courier')
            ->get()
            ->keyBy('carrier');

        $days = $this->daysToDeliveryIn($window);

        return new CourierPerformance($window, array_map(
            function (Courier $courier) use ($counts, $days): CourierPerformanceRow {
                $row = $counts->get($courier->value);

                return new CourierPerformanceRow(
                    courier: $courier,
                    sent: (int) ($row->sent ?? 0),
                    delivered: (int) ($row->delivered ?? 0),
                    returned: (int) ($row->came_back ?? 0),
                    charges: ((int) ($row->charges_named ?? 0)) === 0 ? null : (int) $row->charges,
                    averageDaysToDelivery: $days[$courier->value] ?? null,
                );
            },
            Courier::cases(),
        ));
    }

    /**
     * The Orders an operator must act on as of one instant, whatever any
     * Reporting Window says: New Orders older than a day, Payments Pending
     * for over an hour, Orders Paid twice, Orders Paid and Cancelled at
     * once, Orders whose parcel came back and nobody has decided about,
     * Orders whose parcel half arrived and nobody has said what came back,
     * and Orders whose parcel nobody has heard about in a week. Seven
     * queries, one per condition, each returning whole Orders with the
     * instant the condition began loaded beside them; the rows come back in
     * condition order, oldest first within each, and the largest thing looped
     * over is the list of Orders that need attention. Soft-deleted Orders are
     * gone through the model's scope, whatever their Payments or Shipments
     * say.
     *
     * The three parcel conditions are read off the Shipments and not one of
     * them re-states a rule that lives elsewhere: "nothing has been decided"
     * is `Order::notYetSent()`, the same scope the delivery queue filters by;
     * "nobody has said what came back" is `Shipment::awaitingAnswer()`, the
     * same reading the ledger refuses a second answer on; and "nobody has
     * heard about it in a week" is the `stuck_at` the sweep writes —
     * `CourierSweep::STUCK_AFTER_SECONDS` is measured there and never again
     * here, or a horizon changed on the sweep would leave this list quietly
     * disagreeing with it.
     */
    public function needsAttention(?CarbonImmutable $now = null): NeedsAttention
    {
        $now ??= CarbonImmutable::now();

        $pendingSince = $now->subMinutes(self::PENDING_PAYMENT_ATTENTION_AFTER_MINUTES)->utc()->toDateTimeString();
        $stalePending = fn (Builder $payments): Builder => $payments->where('created_at', '<', $pendingSince);

        /**
         * When a Paid Payment was verified; its start where a row somehow
         * carries no verification time, so a refund is never dated "now".
         */
        $lastPaidAt = new Expression('coalesce(verified_at, created_at)');

        /** The attempt that came back, the attempt that half arrived, and the attempt nobody can account for: each asked for twice, once to find the Order and once to date the condition. */
        $returned = fn (Builder $shipments): Builder => $shipments->where('status', ShipmentStatus::Returned->value);
        $halfArrived = fn (Builder $shipments): Builder => $shipments->awaitingAnswer();
        $stuck = fn (Builder $shipments): Builder => $shipments->active()->whereNotNull('stuck_at');

        return new NeedsAttention([
            ...$this->attentionRows(
                AttentionReason::NewOrderUncalled,
                Order::query()
                    ->where('status', OrderStatus::New)
                    ->where('created_at', '<', $now->subHours(self::NEW_ORDER_ATTENTION_AFTER_HOURS)->utc()->toDateTimeString())
                    ->selectRaw('orders.*, created_at as since'),
            ),
            ...$this->attentionRows(
                AttentionReason::PaymentPending,
                Order::query()
                    ->whereHas('pendingPayments', $stalePending)
                    ->withMin(['pendingPayments as since' => $stalePending], 'created_at'),
            ),
            ...$this->attentionRows(
                AttentionReason::PaidTwice,
                Order::query()
                    ->has('paidPayments', '>', 1)
                    ->withMax('paidPayments as since', $lastPaidAt),
            ),
            ...$this->attentionRows(
                AttentionReason::PaidAndCancelled,
                Order::query()
                    ->paidButCancelled()
                    ->withMax('paidPayments as since', $lastPaidAt),
            ),

            /**
             * A parcel came back and nothing has been decided since: no
             * parcel out, nothing of this Order's ever at a door
             * (`notYetSent()`), and the Order not Cancelled — because
             * cancelling it *is* one of the two decisions, and the other is
             * sending it again, which puts a parcel out and takes the Order
             * off this list by itself. An Order whose re-send was itself
             * written off is here again, and should be: nobody has decided
             * what becomes of it.
             *
             * Since the return was recorded, which is the last thing written
             * to that row: a terminal attempt is no longer polled, so nothing
             * touches it afterwards.
             */
            ...$this->attentionRows(
                AttentionReason::ParcelReturned,
                Order::query()
                    ->notYetSent()
                    ->where('status', '!=', OrderStatus::Cancelled->value)
                    ->whereHas('shipments', $returned)
                    ->withMax(['shipments as since' => $returned], 'updated_at'),
            ),

            /**
             * A parcel that half arrived and nobody has said which of its
             * items came back. The one condition here whose answer the stock
             * ledger itself is waiting on: a Courier reports a collected
             * amount and never a list, so the units are neither returned nor
             * lost until a User names the lines (ADR-0021).
             *
             * Nothing else takes it off this list, and deliberately nothing
             * else does. Cancelling the Order does not say what came back,
             * and neither does sending another parcel — this is a question
             * about a box, where a returned parcel is a question about what
             * to do next. `Shipment::awaitingAnswer()` is the whole rule and
             * it is the model's own.
             *
             * Since the Courier said so, which is the last thing written to
             * that row: a terminal attempt is no longer polled, so nothing
             * touches it until the answer does.
             */
            ...$this->attentionRows(
                AttentionReason::ParcelPartlyDelivered,
                Order::query()
                    ->whereHas('shipments', $halfArrived)
                    ->withMax(['shipments as since' => $halfArrived], 'updated_at'),
            ),

            /**
             * A parcel the sweep has flagged and nobody has resolved: still
             * Active, so a parcel written off or since finished is gone from
             * here, and still stuck, which is written once and never cleared.
             *
             * Since it was handed over, not since it was flagged: what an
             * operator is owed is how long the Customer has been waiting, and
             * the flag is a week into that wait rather than the start of it.
             * A Cancelled Order is listed like any other — a parcel is still
             * out for it, and that is a fact about the Merchant's money
             * whatever they have decided about the Order (ADR-0018).
             */
            ...$this->attentionRows(
                AttentionReason::ParcelStuck,
                Order::query()
                    ->whereHas('shipments', $stuck)
                    ->withMin(['shipments as since' => $stuck], 'booked_at'),
            ),
        ]);
    }

    /**
     * The Orders one condition's query finds, as rows under that condition,
     * oldest first in SQL. The query loads the instant the condition began
     * under `since`; the column is UTC, like every stored time.
     *
     * @param  Builder<Order>  $orders
     * @return list<NeedsAttentionRow>
     */
    private function attentionRows(AttentionReason $reason, Builder $orders): array
    {
        return $orders
            ->orderBy('since')
            ->get()
            ->map(fn (Order $order): NeedsAttentionRow => new NeedsAttentionRow(
                $order,
                $reason,
                CarbonImmutable::parse((string) $order->getAttribute('since'), 'UTC'),
            ))
            ->values()
            ->all();
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
     * How many Visits the window holds, over the whole installation or on one
     * Page — and null where the Merchant is not counting them.
     *
     * Null rather than zero is the whole of how a screen knows to say the
     * feature is off instead of drawing a silent nought, and the switch is
     * read here rather than on a widget so that "not counted" has one
     * wording, as every other counting rule does.
     */
    private function visitsIn(ReportingWindow $window, ?Page $page = null): ?int
    {
        if (! $this->countsVisits()) {
            return null;
        }

        return (int) $this->visitsOverDays($window)
            ->when($page, fn (Builder $query, Page $page) => $query->where('page_id', $page->getKey()))
            ->sum('visits');
    }

    /**
     * The window's Visits per Page, keyed by Page — and null where the
     * Merchant is not counting them, which is how the leaderboard tells a
     * Page with no Visits apart from an installation with no counting.
     *
     * @return array<int, int>|null
     */
    private function visitsByPageIn(ReportingWindow $window): ?array
    {
        if (! $this->countsVisits()) {
            return null;
        }

        return $this->visitsOverDays($window)
            /** Rows orphaned by a hard-deleted Page still count towards the installation and belong to no row here. */
            ->whereNotNull('page_id')
            ->selectRaw('page_id, sum(visits) as visits')
            ->groupBy('page_id')
            ->get()
            ->mapWithKeys(fn (VisitCount $row): array => [(int) $row->page_id => (int) $row->visits])
            ->all();
    }

    /**
     * Whether the Merchant counts Visits at all: the one reading of the
     * switch in this module, and what makes every Visit figure null rather
     * than zero while it is off.
     */
    private function countsVisits(): bool
    {
        return app(TrackingSettings::class)->countsVisits;
    }

    /**
     * The aggregate rows the window covers.
     *
     * Compared as whole days in the Merchant's own timezone, which is what
     * the aggregate is keyed by and what the window's bounds were cut from —
     * never as the UTC instants the Orders beside them are compared against.
     * The window ends at the midnight after its last day, so its local end
     * date is the first day *outside* it.
     *
     * @return Builder<VisitCount>
     */
    private function visitsOverDays(ReportingWindow $window): Builder
    {
        return VisitCount::query()
            ->where('visited_on', '>=', $window->localStart()->toDateString())
            ->where('visited_on', '<', $window->localEnd()->toDateString());
    }

    /**
     * The parcels a Courier accepted inside a window.
     *
     * Cut on `booked_at`, which is written at the one moment a Courier says
     * it has the parcel and at no other — so a row a bulk booking wrote and
     * the sweep has not offered yet, and a row a Courier refused when it was
     * offered, are both absent rather than counted as parcels that went out.
     * Neither is excluded by hand: a row that was never accepted holds no
     * `booked_at`, and SQL leaves a null out of a range.
     *
     * A parcel a User later wrote off stays. It was sent and it cost money,
     * and abandoning it is the Merchant's decision rather than the Courier's
     * outcome (ADR-0018) — which is why it counts towards neither the
     * Delivery Rate nor the Return Rate and towards the parcels underneath
     * both.
     *
     * @return Builder<Shipment>
     */
    private function parcelsSentIn(ReportingWindow $window): Builder
    {
        return Shipment::query()
            ->where('booked_at', '>=', $window->start->toDateTimeString())
            ->where('booked_at', '<', $window->end->toDateTimeString());
    }

    /**
     * How long each Courier took over the parcels of a window that arrived,
     * in days, keyed by the name the Courier is stored under — and absent for
     * a Courier none of whose parcels arrived, which the caller reads as
     * nothing rather than as nought.
     *
     * The one figure here counted in PHP, for the reason
     * `courierPerformance()` gives. Both instants are read as they are
     * stored, which is UTC on either side of the subtraction, so no timezone
     * enters it: what the Merchant's own days decide is which parcels are in
     * front of us, and the window has already decided that.
     *
     * @return array<string, float>
     */
    private function daysToDeliveryIn(ReportingWindow $window): array
    {
        $carried = [];
        $parcels = [];

        foreach ($this->parcelsSentIn($window)->arrived()->toBase()->get(['courier', 'booked_at', 'updated_at']) as $parcel) {
            $courier = (string) $parcel->courier;

            $carried[$courier] = ($carried[$courier] ?? 0) + max(0, self::instant($parcel->updated_at) - self::instant($parcel->booked_at));
            $parcels[$courier] = ($parcels[$courier] ?? 0) + 1;
        }

        $days = [];

        foreach ($carried as $courier => $seconds) {
            $days[$courier] = $seconds / $parcels[$courier] / self::SECONDS_A_DAY;
        }

        return $days;
    }

    /**
     * A stored timestamp as seconds, read the way it was written.
     *
     * The zone is stated rather than left to the process, which reads a naked
     * timestamp in whatever `app.timezone` says. That is UTC here and the two
     * instants either side of a subtraction would cancel it anyway — but a
     * zone with daylight saving would silently make one parcel an hour
     * quicker than it was, twice a year, which is the kind of thing nobody
     * ever traces back to this line.
     */
    private static function instant(mixed $stored): int
    {
        return (int) strtotime((string) $stored.' UTC');
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
     * The Orders of a window that count towards Sales: the ones not
     * Cancelled. What a Cancelled Order sold was never sold and what it
     * quoted was never collected, so the Product ranking and the Delivery
     * mix read this rather than `ordersIn()`.
     *
     * @return Builder<Order>
     */
    private function notCancelledIn(ReportingWindow $window, ?Page $page = null): Builder
    {
        return $this->ordersIn($window, $page)->where('status', '!=', OrderStatus::Cancelled->value);
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
