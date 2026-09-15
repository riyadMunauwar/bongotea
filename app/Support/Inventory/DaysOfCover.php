<?php

namespace App\Support\Inventory;

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Support\Settings\InventorySettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How long a Product's On Hand lasts at the rate it is going out.
 *
 * Separate from `StockLedger` for the reason `CommittedStock` is: the ledger
 * writes movements and answers what is held, and this writes nothing at all —
 * it reads two figures the ledger already holds and divides one by the other.
 * Nothing is stored, and there is no threshold per Product to maintain: the
 * figure keeps up on its own the moment a campaign moves the rate, which is
 * the whole reason cover is measured in days rather than in units.
 *
 * **The rate is read over a fixed trailing span of its own and never over a
 * screen's Reporting Window.** A Merchant narrowing a window to Today is
 * asking a question about a day's Sales; they are not saying that every
 * Product in the catalogue now sells at its rate since this morning. Wiring
 * cover to that control would change every velocity in the catalogue from a
 * filter about something else, and would make the to-do list quietly shrink
 * as the window narrowed — the one thing a to-do list must never do.
 *
 * Unknown is not zero here either, twice over (ADR-0021). A Product nobody has
 * counted has no On Hand and therefore no cover. A Product nothing has gone
 * out of in the span has no rate, and the honest answer to "how long will it
 * last" is nothing at all rather than for ever: a dormant Product presenting
 * itself as infinitely safe is exactly the reading that gets a campaign
 * started on a shelf nobody has looked at.
 */
class DaysOfCover
{
    /**
     * The trailing span the rate is read over, in days.
     *
     * Four whole weeks, so a weekly rhythm — a Friday every Product sells on,
     * a Sunday nobody orders — falls the same number of times whatever day the
     * figure is read on. A span of seven would swing with the weekday, and one
     * of ninety would take a month to notice a campaign.
     *
     * A constant and not a Setting, deliberately: a Merchant moving it would
     * be changing what velocity means rather than saying how much warning they
     * want, and the one number they do get to say is the threshold.
     */
    public const SPAN_DAYS = 28;

    /**
     * What a query loaded by `withUnitsShipped()` carries the rate's numerator
     * under. Named once, because a column, this class and a test all have to
     * spell it the same way — the reason `StockLedger::ON_HAND` is named once.
     */
    public const UNITS_SHIPPED = 'units_shipped';

    /**
     * What the figure itself is called on a screen.
     *
     * Not a loaded column and never will be: cover is one summed subquery
     * divided by another, so it is worked out in PHP by `read()` and this is
     * only the name a table column goes by.
     */
    public const COVER = 'days_of_cover';

    /**
     * How many units of each Product left on a parcel inside the span, loaded
     * into the query a list already runs, under `self::UNITS_SHIPPED`.
     *
     * Read off the Sale movements, which is the ledger's own record of boxes a
     * Courier actually took, and never off the Orders: an Order nobody could
     * reach was never a parcel, and counting it would make every campaign's
     * cover look thinner than the shelf really is (ADR-0021).
     *
     * Sales alone, and a parcel that came back does not un-ship itself. What
     * this measures is the rate units leave the building at, because that is
     * the rate at which the shelf has to be able to produce them; a Merchant
     * whose parcels half come back still has to have the stock to send them
     * out in the first place.
     *
     * A Sale's quantity is negative — units going out — so what is summed is
     * its opposite, and a Product nothing has gone out of sums to null, which
     * is the same "no rate" `of()` reads as no cover.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function withUnitsShipped(Builder $products, ?CarbonImmutable $now = null): Builder
    {
        $since = $this->spanBegan($now);

        return $products->withSum(
            ['stockMovements as '.self::UNITS_SHIPPED => fn (Builder $query): Builder => $query
                ->where('reason', StockMovementReason::Sale)
                ->where('created_at', '>=', $since)],
            DB::raw('-quantity'),
        );
    }

    /**
     * The figure itself: how many days what is held lasts at the rate it went
     * out over the span.
     *
     * The one place the arithmetic is written down, so a list column, a to-do
     * list and a test cannot arrive at three answers. Both halves may be
     * unknown and either one makes the whole unknown — a shelf nobody has
     * counted and a Product nothing has gone out of both read as no cover,
     * never as zero and never as for ever.
     *
     * Below nothing where On Hand is, and meant: a shelf already in deficit
     * that is still shipping is the loudest thing this can say, and rounding
     * it up to zero would file it beside a shelf that has merely run out.
     *
     * Written as held × span ÷ shipped rather than as held ÷ (shipped ÷ span),
     * which is the same figure and the same sentence — units held against
     * units shipped per day — arrived at without a daily rate in the middle
     * for the division to round off. Four units against eight over a
     * twenty-eight-day span is exactly fourteen days one way round and a
     * fraction short of it the other, and a figure a to-do list sorts on is
     * owed the exact one.
     *
     * @param  int|null  $onHand  What the ledger holds, or null where nobody has counted.
     * @param  int|null  $unitsShipped  What left on parcels over the span, or null where nothing did.
     */
    public function of(?int $onHand, ?int $unitsShipped): ?float
    {
        if ($onHand === null || $unitsShipped === null || $unitsShipped <= 0) {
            return null;
        }

        return $onHand * self::SPAN_DAYS / $unitsShipped;
    }

    /**
     * The figure for a Product a query has already loaded both halves onto,
     * by `withOnHand()` and `withUnitsShipped()`.
     *
     * The one reader a list uses, so a row on the Products table and a row on
     * the to-do list cannot be arrived at two ways. A `withSum` over no rows
     * is null, and that null is carried through rather than cast to a nought
     * that would read as a shelf somebody counted empty.
     */
    public function read(Product $product): ?float
    {
        return $this->of(
            self::figure($product->getAttribute(StockLedger::ON_HAND)),
            self::figure($product->getAttribute(self::UNITS_SHIPPED)),
        );
    }

    /**
     * Whether a cover figure is under what the Merchant called thin.
     *
     * The one place the comparison is made, so the to-do list, the Products
     * list column and anything that comes later cannot draw the line in three
     * places — the same reason `of()` is the only place the division is
     * written. An unknown cover is not a thin one: a shelf nobody has counted
     * and a Product nothing has gone out of are questions, not to-dos.
     */
    public function isThin(?float $daysOfCover): bool
    {
        return $daysOfCover !== null && $daysOfCover < app(InventorySettings::class)->thinCoverDays;
    }

    /**
     * The same figure for one Product, in the one query the two halves take.
     *
     * Trashed and all: a Product withdrawn from the catalogue is off the to-do
     * list, but its own stock screen still answers for it.
     */
    public function forProduct(Product $product, ?CarbonImmutable $now = null): ?float
    {
        $ledger = app(StockLedger::class);

        $loaded = $this->withUnitsShipped($ledger->withOnHand(Product::withTrashed()->whereKey($product->getKey())), $now)->first();

        return $loaded === null ? null : $this->read($loaded);
    }

    /**
     * Every Product whose cover is under what the Merchant called thin,
     * thinnest first, with the figure beside it.
     *
     * One query for the whole catalogue: both halves are summed into it the
     * way the Products list already sums On Hand, so this costs a query rather
     * than a query per Product.
     *
     * The catalogue as it stands, and trashed Products are not in it. A
     * withdrawn Product holding units is capital and belongs on the Stock
     * report for exactly that reason, but nobody is going to buy more of
     * something they have stopped selling, and a to-do list is a list of
     * things somebody will actually do.
     *
     * A Product with no cover at all is not thin — it is unknown, and
     * `isThin()` is what says so. Nothing here restates either rule: the
     * figure is `read()`'s and the line is `isThin()`'s.
     *
     * @return list<ThinShelf>
     */
    public function runningOut(?CarbonImmutable $now = null): array
    {
        $ledger = app(StockLedger::class);

        $products = $this->withUnitsShipped($ledger->withOnHand(Product::query()), $now)->get();

        $thin = [];

        foreach ($products as $product) {
            $cover = $this->read($product);

            if ($this->isThin($cover)) {
                $thin[] = new ThinShelf($product, $cover);
            }
        }

        usort(
            $thin,
            /** Thinnest first, and by name where two shelves are as thin as each other, so the order never depends on how the rows came back. */
            fn (ThinShelf $a, ThinShelf $b): int => [$a->daysOfCover, strtolower($a->product->name)]
                <=> [$b->daysOfCover, strtolower($b->product->name)],
        );

        return $thin;
    }

    /**
     * When the trailing span began.
     *
     * Whole days back from the instant asked about rather than from a local
     * midnight, because this is a rate and not a report: what it measures is
     * "the last four weeks", which is the same four weeks whatever hour of the
     * Merchant's day the screen happens to be drawn at. The Reporting Window's
     * day boundaries are for figures a Merchant reads against a calendar, and
     * this is deliberately not one of them.
     */
    private function spanBegan(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subDays(self::SPAN_DAYS)->utc();
    }

    /**
     * A summed column as an int, or null where nothing was summed.
     *
     * A `withSum` over no rows is null, which is the unknown both halves mean
     * by it — so the null is carried through rather than cast to a nought that
     * would read as a counted empty shelf.
     */
    private static function figure(mixed $loaded): ?int
    {
        return $loaded === null ? null : (int) $loaded;
    }
}
