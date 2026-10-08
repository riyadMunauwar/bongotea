<?php

namespace App\Support\Reporting;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\SupplyLine;
use App\Models\VisitCount;
use App\Support\Inventory\DaysOfCover;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\ThinShelf;
use App\Support\Settings\TrackingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;

/**
 * The one seam every report widget calls, and the only place the glossary's
 * counting rules are written in SQL. It takes a Reporting Window and hands
 * back plain typed results; a widget formats and draws, and never counts.
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
 * beacon writes, which is a count per day rather than a row per
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
 * Deliveries are read the same way and cut on the day the goods came:
 * `supplies.supplied_on` is a date keyed by the Merchant's own calendar, so it
 * is compared as whole days like the Visit aggregate and never as the UTC
 * instants an Order is cut on.
 *
 * The overview, the Sales over time series, the status breakdown, the Payment Method mix, the Product ranking, the
 * Delivery mix, the courier performance, the buying figures, the stock held
 * and the Needs Attention list are here. A later result is another method
 * returning another result class, built on `ordersIn()`, `notCancelledIn()`,
 * `parcelsSentIn()` or `deliveriesIn()`, and no widget changes for it.
 *
 * Two results take no window, and each says why in its own class. Needs
 * Attention is a to-do list over the whole installation; the stock held is
 * what is on the shelf as things stand, which has no span of days behind it
 * at all.
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
    public function salesOverTime(ReportingWindow $window): SalesOverTime
    {
        $buckets = $window->buckets();
        $cancelled = OrderStatus::Cancelled->value;

        $rows = $this->groupedByBucket(
            $this->ordersIn($window),
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
    public function overview(ReportingWindow $window): Overview
    {
        $buckets = $this->figuresByBucket($window, $window->buckets());

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
            $figures->withVisits($this->visitsIn($window)),
            $this->figuresByBucket($comparison, [$whole])[0]->withVisits($this->visitsIn($comparison)),
            $buckets,
        );
    }

    /**
     * How many of the window's Orders are New, Confirmed and Cancelled, for
     * the doughnut: one query, three status counts on one row.
     */
    public function statusBreakdown(ReportingWindow $window): StatusBreakdown
    {
        $row = $this->ordersIn($window)
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
    public function paymentMix(ReportingWindow $window): PaymentMix
    {
        $cancelled = OrderStatus::Cancelled->value;

        $perMethod = $this->ordersIn($window)
            ->selectRaw('payment_method as method, count(*) as order_count, '.self::SALES, [$cancelled])
            ->groupBy('payment_method')
            ->get()
            ->keyBy('method');

        $unpaid = $this->ordersIn($window)
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
    public function productRanking(ReportingWindow $window): ProductRanking
    {
        $rows = OrderLine::query()
            ->joinSub($this->notCancelledIn($window)->select('id'), 'counted', 'counted.id', '=', 'order_lines.order_id')
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
     * What a window earned rather than what it turned over: revenue, stamped
     * cost and what is left, per Product and across them all.
     *
     * Two queries over the very same Orders — the ones `notCancelledIn()`
     * hands back — and one lookup of the Products named. The first reads the
     * Orders' own money, and it is the one that makes revenue **`Sales`**:
     * the totals of the Orders not Cancelled is what the glossary means by
     * the word and what `SALES` sums a bucket at a time, so counting it here
     * off the same scope cannot drift from the Dashboard's figure. The
     * Delivery Charge inside it comes back beside it, because it belongs to
     * an Order rather than to any Product on it and so can appear on no row.
     *
     * The second reads the Lines, grouped exactly as the Top Products ranking
     * groups them, with the cost stamped at Capture summed alongside the
     * revenue. An **uncosted** Line adds nothing to the cost and its units are
     * counted apart, which is what lets a row and the window say that a margin
     * is missing part of its cost rather than quietly drawing one that is too
     * high (ADR-0022).
     */
    public function margin(ReportingWindow $window): Margin
    {
        $money = $this->notCancelledIn($window)
            ->selectRaw('sum(total) as sales, sum(delivery_charge) as delivery_charge')
            ->first();

        $rows = OrderLine::query()
            ->joinSub($this->notCancelledIn($window)->select('id'), 'counted', 'counted.id', '=', 'order_lines.order_id')
            ->selectRaw(
                'product_id, min(product_name) as product_name, '
                .'sum(quantity) as units, '
                .'sum(line_total) as revenue, '
                .'sum(case when unit_cost is null then 0 else quantity * unit_cost end) as cost, '
                .'sum(case when unit_cost is null then 0 else quantity end) as costed_units',
            )
            ->groupByRaw("product_id, (case when product_id is null then product_name else '' end)")
            ->get();

        $products = Product::withTrashed()
            ->findMany($rows->pluck('product_id')->filter())
            ->keyBy(fn (Product $product): int => $product->getKey());

        $margins = $rows
            ->map(fn (object $row): MarginRow => new MarginRow(
                product: $row->product_id === null ? null : $products->get((int) $row->product_id),
                recordedName: (string) $row->product_name,
                units: (int) $row->units,
                revenue: (int) $row->revenue,

                /** Unknown where not one unit of it was stamped, rather than the nought the sum came to. */
                cost: (int) $row->costed_units === 0 ? null : (int) $row->cost,
                costedUnits: (int) $row->costed_units,
            ))
            ->sortBy([
                /** A Product nobody has priced has not made nothing, so it sorts below every Product that has made something. */
                fn (MarginRow $a, MarginRow $b): int => ($a->margin() === null) <=> ($b->margin() === null),
                fn (MarginRow $a, MarginRow $b): int => ($b->margin() ?? 0) <=> ($a->margin() ?? 0),
                fn (MarginRow $a, MarginRow $b): int => $b->revenue <=> $a->revenue,
                fn (MarginRow $a, MarginRow $b): int => strcasecmp($a->name(), $b->name()),
            ])
            ->values()
            ->all();

        return Margin::of(
            window: $window,
            rows: $margins,
            revenue: (int) ($money?->sales ?? 0),
            deliveryCharge: (int) ($money?->delivery_charge ?? 0),
        );
    }

    /**
     * What every Product holds right now and what it is worth, and the money
     * held altogether.
     *
     * **The one result here that takes no Reporting Window.** It answers what
     * is on the shelf as things stand, and there is no span of days behind
     * that — which is why the screen drawing it offers no window control
     * rather than a dead one.
     *
     * Nothing is counted here. Both figures are the ledger's own, summed into
     * the one query this runs through `withOnHand()` and `withValueAtCost()`,
     * because On Hand is the sum of a Product's movements and is stored in no
     * column (ADR-0021). A null from either is the unknown the ledger means by
     * it and is carried through untouched: a Product nobody has counted is not
     * a Product holding nothing.
     *
     * Every Product on the catalogue is a row. A Product withdrawn from it
     * joins them while it still holds something — units on a shelf are capital
     * whether or not anybody is still selling them, and the total held would
     * understate what the Merchant owns without them — and drops off once it
     * holds nothing, so a retired line does not sit on the report for ever.
     */
    public function stockHoldings(): StockHoldings
    {
        $ledger = app(StockLedger::class);

        $rows = $ledger->withValueAtCost($ledger->withOnHand(Product::withTrashed()))
            ->get()
            ->map(fn (Product $product): StockHoldingRow => new StockHoldingRow(
                product: $product,
                onHand: self::intOrUnknown($product->getAttribute(StockLedger::ON_HAND)),
                valueAtCost: self::intOrUnknown($product->getAttribute(StockLedger::VALUE_AT_COST)),
            ))
            ->filter(fn (StockHoldingRow $row): bool => ! $row->isDeleted() || $row->holdsSomething())
            ->sortBy([
                /** A shelf nobody has priced is not a shelf worth nothing, so it sorts below every one that is. */
                fn (StockHoldingRow $a, StockHoldingRow $b): int => ($a->valueAtCost === null) <=> ($b->valueAtCost === null),
                fn (StockHoldingRow $a, StockHoldingRow $b): int => ($b->valueAtCost ?? 0) <=> ($a->valueAtCost ?? 0),
                fn (StockHoldingRow $a, StockHoldingRow $b): int => ($b->onHand ?? 0) <=> ($a->onHand ?? 0),
                fn (StockHoldingRow $a, StockHoldingRow $b): int => strcasecmp($a->name(), $b->name()),
            ])
            ->values()
            ->all();

        /** The money held is the rows' own business and is worked out there, so it cannot disagree with them. */
        return StockHoldings::of($rows);
    }

    /**
     * What was bought inside a window, read by Supplier and by Product, with
     * what it all came to.
     *
     * Two grouped queries over the lines of the deliveries that count, which
     * is the same set of lines counted along two axes — so the two lists
     * always add up to the same totals, and the totals are read off one of
     * them rather than counted a third time. A Voided delivery is in neither:
     * `deliveriesIn()` is what says so, once.
     *
     * Deliveries per Supplier is a count of Supplies and never of lines. One
     * invoice is one delivery however many Products it named, which is what
     * makes it the figure a Merchant means by "they deliver to me twice a
     * month".
     *
     * Neither Supplier nor Product can be missing: both foreign keys are
     * restricted rather than cascading, so nobody who ever delivered anything
     * and nothing that was ever bought can be erased out from under its own
     * history. Both are loaded trashed and all, so a row says it has been
     * retired rather than disappearing.
     */
    public function buying(ReportingWindow $window): Buying
    {
        $perSupplier = SupplyLine::query()
            ->joinSub($this->deliveriesIn($window)->select('id', 'supplier_id'), 'bought', 'bought.id', '=', 'supply_lines.supply_id')
            ->selectRaw(
                'bought.supplier_id as supplier_id, '
                .'count(distinct supply_lines.supply_id) as deliveries, '
                .'sum(quantity) as units, '
                .'sum(quantity * unit_cost) as cost',
            )
            ->groupBy('bought.supplier_id')
            ->get();

        $perProduct = SupplyLine::query()
            ->joinSub($this->deliveriesIn($window)->select('id'), 'bought', 'bought.id', '=', 'supply_lines.supply_id')
            ->selectRaw('product_id, sum(quantity) as units, sum(quantity * unit_cost) as cost')
            ->groupBy('product_id')
            ->get();

        $suppliers = Supplier::withTrashed()
            ->findMany($perSupplier->pluck('supplier_id'))
            ->keyBy(fn (Supplier $supplier): int => $supplier->getKey());

        $products = Product::withTrashed()
            ->findMany($perProduct->pluck('product_id'))
            ->keyBy(fn (Product $product): int => $product->getKey());

        $bySupplier = $perSupplier
            ->map(fn (object $row): BuyingSupplierRow => new BuyingSupplierRow(
                supplier: $suppliers->get((int) $row->supplier_id),
                deliveries: (int) $row->deliveries,
                units: (int) $row->units,
                cost: (int) $row->cost,
            ))
            ->sortBy([
                fn (BuyingSupplierRow $a, BuyingSupplierRow $b): int => $b->cost <=> $a->cost,
                fn (BuyingSupplierRow $a, BuyingSupplierRow $b): int => $b->units <=> $a->units,
                fn (BuyingSupplierRow $a, BuyingSupplierRow $b): int => strcasecmp($a->name(), $b->name()),
            ])
            ->values();

        $byProduct = $perProduct
            ->map(fn (object $row): BuyingProductRow => new BuyingProductRow(
                product: $products->get((int) $row->product_id),
                units: (int) $row->units,
                cost: (int) $row->cost,
            ))
            ->sortBy([
                fn (BuyingProductRow $a, BuyingProductRow $b): int => $b->cost <=> $a->cost,
                fn (BuyingProductRow $a, BuyingProductRow $b): int => $b->units <=> $a->units,
                fn (BuyingProductRow $a, BuyingProductRow $b): int => strcasecmp($a->name(), $b->name()),
            ])
            ->values();

        return new Buying(
            window: $window,
            bySupplier: $bySupplier->all(),
            byProduct: $byProduct->all(),

            /**
             * Added up off the Supplier rows rather than counted again, so
             * every figure on the screen agrees with the rows above it — a
             * total counted separately is a total that can disagree with what
             * it is meant to be the sum of.
             */
            deliveries: (int) $bySupplier->sum(fn (BuyingSupplierRow $row): int => $row->deliveries),
            units: (int) $bySupplier->sum(fn (BuyingSupplierRow $row): int => $row->units),
            cost: (int) $bySupplier->sum(fn (BuyingSupplierRow $row): int => $row->cost),
        );
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
    public function deliveryMix(ReportingWindow $window): DeliveryMix
    {
        $rows = $this->notCancelledIn($window)
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

        $districts = $this->notCancelledIn($window)
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
     * What an operator must act on as of one instant, whatever any Reporting
     * Window says: New Orders older than a day, Payments Pending for over an
     * hour, Orders Paid twice, Orders Paid and Cancelled at once, Orders whose
     * parcel came back and nobody has decided about, Orders whose parcel half
     * arrived and nobody has said what came back, Orders whose parcel nobody
     * has heard about in a week, and Products whose shelf will not outlast the
     * rate it is going out at. Eight queries, one per condition, each
     * returning whole records with the instant the condition began loaded
     * beside them; the rows come back in condition order, oldest first within
     * each, and the largest thing looped over is the list that needs
     * attention. Soft-deleted Orders are gone through the model's scope,
     * whatever their Payments or Shipments say.
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
     *
     * The eighth borrows its rules the same way: what counts as thin cover is
     * the Merchant's own Setting, and the figure is `DaysOfCover`'s, read over
     * a trailing span of its own rather than over any window a screen might be
     * showing.
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

            ...$this->thinCoverRows($now),
        ]);
    }

    /**
     * The Products whose shelves will not outlast the rate they are going out
     * at, thinnest first, each carrying the figure that put it there.
     *
     * The one condition here that is not about an Order, and the one with no
     * `since`: cover is read fresh from a rate that moves under it, so no day
     * is the day it crossed the line and a blank is the honest account of that
     * (ADR-0021).
     *
     * Nothing about cover is worked out here. What counts as thin is the
     * Merchant's own Setting and what the figure is is `DaysOfCover`'s, read
     * over a trailing span of its own — so narrowing the Reporting Window
     * moves neither, which is what keeps this a to-do list rather than a
     * report that quietly shrinks.
     *
     * @return list<NeedsAttentionRow>
     */
    private function thinCoverRows(CarbonImmutable $now): array
    {
        return array_map(
            fn (ThinShelf $shelf): NeedsAttentionRow => new NeedsAttentionRow(
                subject: $shelf->product,
                reason: AttentionReason::StockRunningOut,
                daysOfCover: $shelf->daysOfCover,
            ),
            app(DaysOfCover::class)->runningOut($now),
        );
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
                subject: $order,
                reason: $reason,
                since: CarbonImmutable::parse((string) $order->getAttribute('since'), 'UTC'),
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
    private function figuresByBucket(ReportingWindow $window, array $buckets): array
    {
        $cancelled = OrderStatus::Cancelled->value;
        $new = OrderStatus::New->value;

        $counted = $this->groupedByBucket(
            $this->ordersIn($window),
            $buckets,
            'count(*) as order_count, '
            .self::SALES.', '
            .self::STATUS_COUNT.' as cancelled, '
            .self::STATUS_COUNT.' as new_orders',
            [$cancelled, $cancelled, $new],
        );

        $unpaid = $this->groupedByBucket($this->ordersIn($window)->unpaid(), $buckets, 'count(*) as unpaid');

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
     * How many Visits the window holds — and null where the Merchant is not
     * counting them.
     *
     * Null rather than zero is the whole of how a screen knows to say the
     * feature is off instead of drawing a silent nought, and the switch is
     * read here rather than on a widget so that "not counted" has one
     * wording, as every other counting rule does.
     */
    private function visitsIn(ReportingWindow $window): ?int
    {
        if (! $this->countsVisits()) {
            return null;
        }

        return (int) $this->visitsOverDays($window)
            ->sum('visits');
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
     * The deliveries a window covers: the Supplies that stand, dated by the
     * day the goods came.
     *
     * Compared as whole days in the Merchant's own timezone, which is what
     * `supplies.supplied_on` is keyed by — a date rather than an instant,
     * because nobody knows the hour a van arrived. That is the Visit
     * aggregate's reading and never the UTC instants an Order is cut on. The
     * window ends at the midnight after its last day, so its local end date is
     * the first day outside it.
     *
     * Voided ones are gone through the model's own scope, which is the whole
     * of "a delivery that counts" and is not restated at a call site: the
     * ledger has already taken every unit of a voided delivery back out, and a
     * buying figure counting it would say the Merchant spends more than they
     * do.
     *
     * A fresh builder each time it is asked for, because two grouped queries
     * join it and an Eloquent builder is mutable.
     *
     * @return Builder<Supply>
     */
    private function deliveriesIn(ReportingWindow $window): Builder
    {
        return Supply::query()
            ->notVoided()
            ->where('supplied_on', '>=', $window->localStart()->toDateString())
            ->where('supplied_on', '<', $window->localEnd()->toDateString());
    }

    /**
     * A figure a ledger sum loaded into a query, as an int — and null left as
     * null, because that is the ledger's word for unknown and not a missing
     * value to be filled in with a nought.
     */
    private static function intOrUnknown(mixed $loaded): ?int
    {
        return $loaded === null ? null : (int) $loaded;
    }

    /**
     * The Orders captured inside a window, soft-deleted ones already gone
     * through the model's scope.
     *
     * @return Builder<Order>
     */
    private function ordersIn(ReportingWindow $window): Builder
    {
        return Order::query()
            ->where('created_at', '>=', $window->start->toDateTimeString())
            ->where('created_at', '<', $window->end->toDateTimeString());
    }

    /**
     * The Orders of a window that count towards Sales: the ones not
     * Cancelled. What a Cancelled Order sold was never sold and what it
     * quoted was never collected, so the Product ranking and the Delivery
     * mix read this rather than `ordersIn()`.
     *
     * @return Builder<Order>
     */
    private function notCancelledIn(ReportingWindow $window): Builder
    {
        return $this->ordersIn($window)->where('status', '!=', OrderStatus::Cancelled->value);
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
