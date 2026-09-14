<?php

namespace App\Support\Inventory;

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one thing in the system that writes a Stock Movement, and the one thing
 * that reads On Hand.
 *
 * Everything else asks it a question in the Merchant's own words — how many of
 * this are held, somebody counted this many — and never touches the rows.
 * That is the whole design: On Hand is the sum of the movements and is stored
 * nowhere (ADR-0021), so a figure is only as trustworthy as the number of
 * places allowed to write one. This is that number.
 *
 * It is deliberately narrow today. A delivery arrives and raises what is
 * held; a User counts, and the difference is squared away as an Adjustment.
 * Shipment, Return and Loss each arrive as another method here and nowhere
 * else — in particular a `ShipmentObserver` will call this rather than write
 * rows of its own, because status alone is written from four places in the
 * courier layer and that is four places the next Courier path could forget.
 *
 * It also answers what a unit is worth. Cost enters at a Supply and is
 * stamped onto every movement as it happens (ADR-0022): the average is read
 * from the rows already written, at the instant of writing, and is a column
 * on nothing — so there is no figure to maintain, none to backfill, and no
 * way for a Supply recorded today to change what last month's movements say.
 */
class StockLedger
{
    /**
     * What a query loaded by `withOnHand()` carries the figure under. Named
     * once, because a table column, a sort and a test all have to spell it
     * the same way.
     */
    public const ON_HAND = 'on_hand';

    /**
     * What a query loaded by `withValueAtCost()` carries the money figure
     * under, named once for the same reason `ON_HAND` is.
     */
    public const VALUE_AT_COST = 'value_at_cost';

    /**
     * How many units of a Product are held, or null where nobody has ever
     * counted it.
     *
     * Null is not zero and the difference is the point: a zero is an
     * instruction to reorder, and a Product nobody has looked at is not a
     * Product that has run out. Every screen drawing this draws nothing
     * rather than `0` — the same distinction a Courier's Balance and its
     * Delivery Rate already make.
     *
     * The figure is allowed to come back negative. A parcel can really be
     * booked for stock nobody recorded arriving, and the honest reading of
     * that is a figure below nothing that asks to be explained.
     */
    public function onHand(Product $product): ?int
    {
        /** One pass: the sum is meaningless without knowing whether anything was summed. */
        $ledger = $product->stockMovements()
            ->toBase()
            ->selectRaw('count(*) as movements, coalesce(sum(quantity), 0) as held')
            ->first();

        return ($ledger?->movements ?? 0) > 0 ? (int) $ledger->held : null;
    }

    /**
     * The same figure for a list of Products, loaded in the query rather than
     * a row at a time, under `self::ON_HAND`.
     *
     * A Product with no movements sums to null, which is exactly the unknown
     * `onHand()` answers with — so the two readers agree without either of
     * them having to say so.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function withOnHand(Builder $products): Builder
    {
        return $products->withSum('stockMovements as '.self::ON_HAND, 'quantity');
    }

    /**
     * What one unit of a Product is worth right now, in minor units, or null
     * where nothing bought has ever been recorded.
     *
     * The average is not stored anywhere and never was (ADR-0022). It is the
     * money the ledger holds divided by the units it holds, read off the rows
     * themselves — which works because a Supply's movement is stamped with
     * what was actually paid and every other movement is stamped with the
     * average in force, so a unit leaving takes exactly its own share of the
     * money with it and the average does not budge.
     *
     * Both halves are read over **every** movement, including the ones
     * carrying no cost. Units nobody recorded buying are units nobody paid a
     * recorded taka for, so they hold the figure down rather than standing
     * outside it — and they have to, because a count taken afterwards is
     * measured against all of them. Leaving them out of the divisor alone
     * would let more money leave the ledger than ever entered it, and a
     * Product holding five units would report a value below nothing.
     *
     * Unknown is still not zero: a Product nothing costed has ever moved for
     * has no average at all rather than an average of nothing. The blend only
     * begins once something has actually been bought, and what it says is
     * true — the shelf holds units that cost this much on average, and the
     * way to raise it is to record the Supply that was missed.
     *
     * Where the units come to nothing or less — the shelf has emptied, or gone
     * below nothing — the figure is the last cost stamped rather than a
     * division by zero. What a unit was worth does not become unknown because
     * the shelf ran out.
     */
    public function averageCost(Product $product): ?int
    {
        $ledger = $this->totals($product);

        if ((int) $ledger->costed === 0) {
            return null;
        }

        if ((int) $ledger->units <= 0) {
            return $this->lastStampedCost($product);
        }

        return (int) round($ledger->value / $ledger->units);
    }

    /**
     * What the units held of a Product are worth altogether, in minor units,
     * or null where nothing costed has ever moved.
     *
     * Every movement at the cost stamped on it, summed — the ledger's own
     * figure, traceable to rows a User can read rather than arrived at by
     * multiplying On Hand by an average. It comes to the same thing as that
     * multiplication, because every movement was stamped with the average in
     * force as it happened; what it adds is that a User can be shown the rows
     * it came from.
     *
     * Below nothing where the ledger itself has gone below nothing, and drawn
     * as loudly: money has left that nothing recorded arriving, which is an
     * unrecorded Supply announcing itself exactly as a negative On Hand is.
     */
    public function valueAtCost(Product $product): ?int
    {
        $ledger = $this->totals($product);

        return (int) $ledger->costed > 0 ? (int) $ledger->value : null;
    }

    /**
     * The same money figure for a list of Products, loaded in the query
     * rather than a row at a time, under `self::VALUE_AT_COST`.
     *
     * A Product with nothing costed against it sums to null, which is the
     * same unknown `valueAtCost()` answers with — the two readers agree
     * without either of them having to say so.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function withValueAtCost(Builder $products): Builder
    {
        return $products->withSum(
            'stockMovements as '.self::VALUE_AT_COST,
            DB::raw('quantity * unit_cost'),
        );
    }

    /**
     * Record a delivery, and raise what is held by it.
     *
     * The document, its lines and the movements they cause are written
     * together or not at all: a Supply on the record that raised nobody's
     * stock would be an invoice filed against a shelf that never heard about
     * it. There is no separate receiving step, because there is no moment
     * between the two for anybody to forget.
     *
     * One movement per line, caused by the line rather than by the document.
     * A delivery listing the same Product twice is two arrivals of it, and
     * pointing both movements at the header would collide on the ledger's
     * one-cause-one-movement key — which exists to stop a Courier saying the
     * same thing twice, not to stop a buyer ordering the same thing twice.
     *
     * This is where cost enters the system (ADR-0022). Each movement is
     * stamped with what that line actually paid, and the Product's weighted
     * average rolls forward as a consequence of the row being there rather
     * than as a figure anybody writes down.
     *
     * @param  list<array{product_id: int|string, quantity: int, unit_cost: int}>  $lines
     */
    public function recordSupply(
        Supplier $supplier,
        CarbonInterface|string $suppliedOn,
        array $lines,
        ?string $reference = null,
        ?User $recordedBy = null,
    ): Supply {
        if ($lines === []) {
            throw new InvalidArgumentException('A Supply is its lines. A delivery of nothing is not a delivery.');
        }

        return DB::transaction(function () use ($supplier, $suppliedOn, $lines, $reference, $recordedBy): Supply {
            $supply = Supply::create([
                'supplier_id' => $supplier->getKey(),
                'supplied_on' => $suppliedOn,
                'reference' => $reference,
                'user_id' => $recordedBy?->getKey(),
            ]);

            foreach ($lines as $line) {
                /** Trashed too: a Product withdrawn from the catalogue can still have a delivery of it arrive. */
                $product = Product::withTrashed()->findOrFail($line['product_id']);

                $supplyLine = $supply->lines()->create([
                    'product_id' => $product->getKey(),
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => (int) $line['unit_cost'],
                ]);

                $this->write(
                    product: $product,
                    quantity: $supplyLine->quantity,
                    reason: StockMovementReason::Supply,
                    source: $supplyLine,
                    user: $recordedBy,

                    /** What was paid, not what the average says: this is the row the average is worked out from. */
                    unitCost: $supplyLine->unit_cost,
                );
            }

            return $supply;
        });
    }

    /**
     * Record what a User counted, and square the ledger with it.
     *
     * The count and its Adjustment are written together or not at all: a
     * count with no movement behind it is a shelf somebody looked at and a
     * figure that never heard about it. The Adjustment's quantity is the
     * difference between what was counted and what the ledger held — worked
     * out here, because nobody in this system ever types a quantity or a
     * difference.
     *
     * A Product nobody has counted holds an unknown rather than zero, and the
     * first count is measured against zero all the same: what the count finds
     * is the opening balance, which is why starting this system up is nothing
     * more than counting the Products that matter this afternoon.
     *
     * A count that agrees with the ledger writes an Adjustment of zero rather
     * than nothing. It is the record that somebody looked and the ledger was
     * right — and on a Product counted for the first time it is also what
     * puts a genuinely empty shelf into the ledger, which nothing else could
     * distinguish from a shelf nobody has visited.
     */
    public function recordCount(
        Product $product,
        int $counted,
        AdjustmentCause $cause,
        ?string $note = null,
        ?User $countedBy = null,
    ): StockCount {
        return DB::transaction(function () use ($product, $counted, $cause, $note, $countedBy): StockCount {
            $count = StockCount::create([
                'product_id' => $product->getKey(),
                'quantity' => $counted,
                'cause' => $cause,
                'note' => $note,
                'user_id' => $countedBy?->getKey(),
            ]);

            $this->write(
                product: $product,
                quantity: $counted - ($this->onHand($product) ?? 0),
                reason: StockMovementReason::Adjustment,
                source: $count,
                user: $countedBy,
            );

            return $count;
        });
    }

    /**
     * Put one row in the ledger.
     *
     * Private, and the reason it is private is the reason this class exists.
     * Every movement is stamped with what a unit was worth at the moment it
     * moved (ADR-0022) and attributed to what caused it, and both of those
     * are properties of the writer rather than of the caller — a second
     * writer somewhere else would be a movement missing one of them, found
     * months later as a figure nobody can account for.
     */
    private function write(
        Product $product,
        int $quantity,
        StockMovementReason $reason,
        Model $source,
        ?User $user,
        ?int $unitCost = null,
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'reason' => $reason,

            /**
             * What a unit was worth as it moved, stamped here and never
             * recomputed (ADR-0022).
             *
             * A Supply says what it paid, because those units cost that and
             * the average is worked out from rows like this one. Everything
             * else is stamped with the average in force at this instant, read
             * from the rows already written — so a Supply recorded tomorrow
             * moves what tomorrow's movements cost and can never move what
             * this one says.
             *
             * Null where nothing bought has ever been recorded, which is what
             * every movement written before the first Supply carries. A
             * weighted average of nothing bought is not zero; it is no figure
             * at all, and a Product with no known cost must read as unknown
             * rather than present itself as infinitely profitable.
             */
            'unit_cost' => $unitCost ?? $this->averageCost($product),

            'user_id' => $user?->getKey(),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
    }

    /**
     * Everything the cost figures are worked out from, in one pass: how many
     * movements carry a cost at all, how many units are held, and what they
     * are worth.
     *
     * One query because the three are one question. How many were summed
     * matters as much as the sum — a Product nothing costed has ever moved
     * for holds an unknown rather than a zero, and the two are only
     * distinguishable by counting.
     */
    private function totals(Product $product): object
    {
        /** `count(unit_cost)` counts the rows carrying one; the sums span every row, and a null cost adds nothing to the money. */
        return $product->stockMovements()
            ->toBase()
            ->selectRaw('count(unit_cost) as costed, coalesce(sum(quantity), 0) as units, coalesce(sum(quantity * unit_cost), 0) as value')
            ->first() ?? (object) ['costed' => 0, 'units' => 0, 'value' => 0];
    }

    /**
     * The last cost this Product's ledger stamped on anything, which is what
     * a unit was worth the last time anybody knew.
     *
     * Only reached where the costed units have come to nothing or less. It is
     * the honest answer there: the shelf emptying does not make the price
     * paid unknowable, and the alternative is either a division by zero or a
     * Product that becomes free the moment it sells out.
     */
    private function lastStampedCost(Product $product): ?int
    {
        return $product->stockMovements()
            ->whereNotNull('unit_cost')
            ->latest('id')
            ->value('unit_cost');
    }
}
