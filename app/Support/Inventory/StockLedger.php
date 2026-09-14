<?php

namespace App\Support\Inventory;

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * held; a User counts, and the difference is squared away as an Adjustment;
 * a delivery that should never have been recorded is Voided, and every unit
 * of it comes back out at what it came in at; a Courier takes a parcel, and
 * the units in it leave. Return and Loss each arrive as another arm of
 * `reconcile()` and nowhere else — `ShipmentObserver` asks this rather than
 * writing rows of its own, because status alone is written from four places
 * in the courier layer and that is four places the next Courier path could
 * forget.
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
     *
     * What is read over is the **standing** rows: a Voided Supply and the
     * reversal that struck it out are both left out, because between them
     * they are a delivery that did not happen. They come to nothing either
     * way, so no figure moves — except on a Product whose only purchase was
     * the voided one, which goes back to having no known cost at all rather
     * than quoting the price on a delivery the Merchant has just struck out.
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
            /** The standing rows, so this reads the same unknown `valueAtCost()` does on a Product whose only delivery was voided. */
            ['stockMovements as '.self::VALUE_AT_COST => fn (Builder $query): Builder => $query->standing()],
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
     * Strike out a delivery that should never have been recorded, and take
     * every unit of it back out.
     *
     * The escape hatch that keeps the ledger honest, and the only one there
     * is. Somebody will type 500 where they meant 50, and the answer is
     * neither an edit nor a deletion: the document stays exactly as it was
     * typed, a time and a name are written against it, and one movement per
     * line says the opposite of what that line said. The mistake and its
     * correction are both on the record, which is the whole difference
     * between a ledger and a spreadsheet.
     *
     * The reversal is stamped with **what that line paid**, not with the
     * average in force (ADR-0022). Every other movement takes its share of
     * the blend because units really left; these units never arrived, so what
     * comes back out is exactly the money that went in — and On Hand and
     * Value at Cost both return to precisely what they were before the
     * Supply, rather than to something near it. Stamp a void at the rolled
     * average instead and a second Supply at a different price leaves the
     * blend permanently wrong.
     *
     * One movement per line and caused by the line, for the same reason the
     * Supply's own movements are: a delivery naming one Product twice is two
     * arrivals, so it is two reversals. Their reason is `Void` rather than
     * `Supply`, which is what lets them point at the same line without
     * colliding on the ledger's one-cause-one-movement key — and what makes
     * the key refuse a second void of the same Supply at the database, under
     * the lock below and after it.
     */
    public function voidSupply(Supply $supply, ?User $voidedBy = null): Supply
    {
        return DB::transaction(function () use ($supply, $voidedBy): Supply {
            /** Read back under the row's own lock: a screen is only as fresh as the moment it was drawn. */
            $locked = Supply::query()->lockForUpdate()->findOrFail($supply->getKey());

            if ($locked->isVoided()) {
                throw new SupplyAlreadyVoided($locked);
            }

            foreach ($locked->lines()->with('product')->get() as $line) {
                $this->write(
                    product: $line->product,
                    quantity: -$line->quantity,
                    reason: StockMovementReason::Void,
                    source: $line,
                    user: $voidedBy,

                    /** What that line paid, so exactly the money it put in comes back out (ADR-0022). */
                    unitCost: $line->unit_cost,
                );
            }

            $locked->fill([
                'voided_at' => now(),
                'voided_by' => $voidedBy?->getKey(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Square the ledger with what has become of one parcel.
     *
     * The one way a Shipment ever moves stock, and the reason it is a
     * question rather than an instruction: `ShipmentObserver` asks this
     * whenever a parcel's status or its abandonment changes, and this decides
     * what — if anything — that leaves the ledger owing (ADR-0021). Status
     * alone is written from four places in the courier layer, and telling
     * each of them what to move would be four places the next Courier path
     * can forget.
     *
     * Stock leaves when the parcel leaves and at no other moment. A Courier
     * answering with a Consignment is what "it left" means, so a Shipment
     * Awaiting Dispatch moves nothing — nobody has been handed anything —
     * and one Refused at Booking moves nothing, because it never went.
     *
     * Asked again as often as anybody likes. A poll and a webhook reporting
     * the same transition at once is ordinary traffic rather than an edge
     * case (ADR-0019), so this is idempotent per cause the way a Shipment
     * Event is: the movements are written against the Shipment, and the
     * ledger's one-cause-one-movement key is what refuses the second telling
     * — at the database, not at a call site.
     */
    public function reconcile(Shipment $shipment): void
    {
        if (! $shipment->wasHandedOver()) {
            return;
        }

        $contents = $this->parcelContents($shipment);

        if ($contents === []) {
            return;
        }

        /**
         * The parcel's Products and what each of them holds, in one query:
         * this runs inside the lock a booking keeps over the Order while a
         * Courier is being waited on, so what it costs is worth counting.
         *
         * Trashed ones too — a Product withdrawn from the catalogue is still
         * in the box somebody just posted.
         */
        $products = $this->withOnHand(Product::withTrashed()->whereKey(array_keys($contents)))->get();

        foreach ($products as $product) {
            /**
             * A Product nobody has counted stays out of the ledger, which is
             * what makes adoption five Products at a time rather than a
             * migration: it holds an unknown rather than a zero, and a parcel
             * of it must not turn that unknown into a figure below nothing
             * that nothing on the shelf agrees with (ADR-0021).
             */
            if ($product->{self::ON_HAND} === null) {
                continue;
            }

            $this->writeOnce(
                product: $product,

                /** Out: the units are in a Courier's van. */
                quantity: -$contents[$product->getKey()],

                reason: StockMovementReason::Sale,
                source: $shipment,
            );
        }
    }

    /**
     * How many units of each Product this parcel carries, keyed by Product,
     * read off the Order the attempt was written for.
     *
     * Summed per Product rather than taken a line at a time, because the
     * cause of the movement is the parcel: the ledger's key is one cause, one
     * Product, one direction, and an Order naming the same Product on two
     * lines is still one Product leaving in one box. The Order's shape is
     * fixed at Capture with a line per Product, so this is the same figure
     * either way — it is written this way so that it stays the same figure if
     * that ever stops being true.
     *
     * Read with the Order trashed or not: a box that left is a box that left,
     * and somebody tidying the Order away afterwards does not put it back on
     * the shelf.
     *
     * @return array<int, int>
     */
    private function parcelContents(Shipment $shipment): array
    {
        $order = $shipment->order()->withTrashed()->first();

        if ($order === null) {
            return [];
        }

        return $order->lines()
            ->whereNotNull('product_id')
            ->toBase()
            ->selectRaw('product_id, sum(quantity) as units')
            ->groupBy('product_id')
            ->pluck('units', 'product_id')
            ->map(fn (mixed $units): int => (int) $units)
            ->all();
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
     * Put one row in the ledger, or leave the one already there alone.
     *
     * For the causes that are told to us more than once. The insert is made
     * and the one-cause-one-movement key is let to refuse it, rather than
     * asked about first — the same choice `Shipment::recordEvent()` makes
     * against the same traffic (ADR-0019): a poll and a webhook arrive
     * together, and a question asked before an insert answers for a moment
     * that has passed by the time the insert lands.
     */
    private function writeOnce(
        Product $product,
        int $quantity,
        StockMovementReason $reason,
        Model $source,
    ): void {
        try {
            $this->write(
                product: $product,
                quantity: $quantity,
                reason: $reason,
                source: $source,

                /**
                 * Nobody's hand. A User may have pressed a button somewhere,
                 * but what moved these units was a Courier — and the same
                 * movement is written by a sweep at four in the morning with
                 * nobody signed in at all.
                 */
                user: null,
            );
        } catch (UniqueConstraintViolationException) {
            /** Somebody told us the same thing twice, which is what the key is there to make harmless. */
        }
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
        /**
         * Over the rows that still stand. A Void and the movement it
         * reversed come to nothing between them, so leaving both out moves
         * neither sum by a taka — what it moves is the count, and with it
         * the answer to whether anything was ever bought at all. A Product
         * whose only costed rows were a delivery that never happened has no
         * known cost, exactly as it had none before that delivery was typed.
         *
         * `count(unit_cost)` counts the rows carrying one; the sums span
         * every standing row, and a null cost adds nothing to the money.
         */
        return $product->stockMovements()
            ->standing()
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
            ->standing()
            ->whereNotNull('unit_cost')
            ->latest('id')
            ->value('unit_cost');
    }
}
