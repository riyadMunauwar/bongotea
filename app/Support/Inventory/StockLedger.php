<?php

namespace App\Support\Inventory;

use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Models\AdjustmentCause;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
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
 * a User adds or removes a known amount, and that same count does the
 * arithmetic for them; a delivery that should never have been recorded is
 * Voided, and every unit of it comes back out at what it came in at; a
 * Courier takes a parcel, and
 * the units in it leave; the parcel comes back, and they return at what they
 * left at; a User gives the parcel up as dead, and they are written off at
 * that same figure. Everything a parcel is ever owed by a Courier's word is
 * an arm of `reconcile()` and nowhere else — `ShipmentObserver` asks this
 * rather than writing rows of its own, because status alone is written from
 * four places in the courier layer and that is four places the next Courier
 * path could forget.
 *
 * `recordPartialReturn()` is the one arm outside it, and has to be. A parcel
 * that half arrived is the single ending a Courier cannot describe — it
 * reports a collected amount and never a list of items (ADR-0021) — so
 * nothing is owed until a User names the lines that came back, and there is
 * no column for an observer to watch for that.
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
     * How many grams of a Product are held, or null where nobody has ever
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
     * What one kilogram of a Product is worth right now, in minor units, or
     * null where nothing bought has ever been recorded.
     *
     * Per kilogram although the ledger counts grams (ADR-0035): every cost is
     * stamped per kilogram, and Σ(grams × cost) ÷ Σ(grams) is a cost per
     * kilogram without converting anything.
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
     * The weighted average cost of many Products at once, keyed by Product id,
     * loaded in a constant number of queries rather than one lookup per Product.
     *
     * Every figure is exactly what `averageCost()` answers for that Product one
     * at a time — the three cases are the same and in the same order: null
     * where nothing costed has ever moved, the last stamped cost where the
     * standing units have come to nothing or less, and the blend otherwise. The
     * arithmetic is not repeated here, only batched, so the two readers cannot
     * drift the way `onHand()` and `withOnHand()` cannot.
     *
     * This exists for the customer-facing Capture, which stamps a line's cost
     * per Product in the basket (ADR-0022): reading each with a correlated
     * subquery would scale the query count of the most latency-sensitive
     * request in the system with basket size. A Product id not asked about, or
     * one nothing has been recorded buying, is absent from the map rather than
     * present as zero — the caller reads a missing key as the same unknown a
     * per-row `averageCost()` answers with.
     *
     * @param  list<int|string>  $productIds
     * @return array<int, int>
     */
    public function averageCostFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** One pass over the standing rows for the whole basket, grouped by Product. */
        $totals = StockMovement::query()
            ->standing()
            ->whereIn('product_id', $productIds)
            ->toBase()
            ->groupBy('product_id')
            ->selectRaw('product_id, count(unit_cost) as costed, coalesce(sum(quantity), 0) as units, coalesce(sum(quantity * unit_cost), 0) as value')
            ->get()
            ->keyBy('product_id');

        /**
         * The Products whose costed units have come to nothing or less, which
         * fall back to the last cost stamped exactly as `averageCost()` does —
         * gathered so that fallback is one more batched query and never one per
         * Product.
         */
        $emptied = $totals
            ->filter(fn (object $row): bool => (int) $row->costed > 0 && (int) $row->units <= 0)
            ->keys()
            ->all();

        $lastStamped = $this->lastStampedCostFor($emptied);

        $costs = [];

        foreach ($totals as $productId => $row) {
            if ((int) $row->costed === 0) {
                continue;
            }

            $costs[(int) $productId] = (int) $row->units <= 0
                ? ($lastStamped[(int) $productId] ?? null)
                : (int) round($row->value / $row->units);
        }

        /** A null fallback drops out, so a missing key stays the one unknown. */
        return array_filter($costs, fn (?int $cost): bool => $cost !== null);
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

        return (int) $ledger->costed > 0 ? Grams::costOfSum($ledger->value) : null;
    }

    /**
     * The same money figure for a list of Products, loaded in the query
     * rather than a row at a time, under `self::VALUE_AT_COST`.
     *
     * A Product with nothing costed against it sums to null, which is the
     * same unknown `valueAtCost()` answers with — the two readers agree
     * without either of them having to say so.
     *
     * Grams × cost per kilogram is summed and divided once in the query
     * (`Grams::summedCost()`), so the column carries minor units, rounded
     * exactly where `valueAtCost()` rounds. A subquery of its own rather than
     * `withSum()`, because `withSum()` can only wrap its column in `sum()` and
     * the division has to sit outside it.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function withValueAtCost(Builder $products): Builder
    {
        if ($products->getQuery()->columns === null) {
            $products->select($products->qualifyColumn('*'));
        }

        return $products->selectSub(
            StockMovement::query()
                /** The standing rows, so this reads the same unknown `valueAtCost()` does on a Product whose only delivery was voided. */
                ->standing()
                ->whereColumn('stock_movements.product_id', $products->qualifyColumn('id'))
                ->selectRaw(Grams::summedCost($products->getConnection())),
            self::VALUE_AT_COST,
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
     * A line's quantity is in grams and its cost is per kilogram (ADR-0035);
     * the Supply form converts the kilograms, packets and cost per packet a
     * Merchant types. A line naming a `pack_id` came packed: the Pack must be
     * that Product's and make the grams exactly, and the line records its
     * weight and the packet count. Either way the grams go into the Product's
     * one pool.
     *
     * @param  list<array{product_id: int|string, quantity: int, unit_cost: int, pack_id?: int|string|null}>  $lines
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
                    ...$this->packing($product, $line['pack_id'] ?? null, (int) $line['quantity']),
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
     * What a Supply line records about the Pack it came in: nothing for a
     * loose delivery, else the Pack, its weight and how many packets the
     * grams make — refused where the Pack is another Product's or cannot
     * make the grams exactly, never rounded.
     *
     * @return array{pack_id?: int, pack_grams?: int, packets?: int}
     */
    private function packing(Product $product, int|string|null $packId, int $grams): array
    {
        if ($packId === null || $packId === '') {
            return [];
        }

        $pack = Pack::query()->whereBelongsTo($product)->find($packId)
            ?? throw new InvalidArgumentException("Pack {$packId} is not one of Product {$product->getKey()}'s Packs.");

        $packets = Grams::packetsIn($grams, $pack->grams)
            ?? throw new InvalidArgumentException("{$grams} g is not a whole number of {$pack->name()} packets.");

        return ['pack_id' => $pack->getKey(), 'pack_grams' => $pack->grams, 'packets' => $packets];
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
     * Three things can be owed, and a parcel can owe more than one of them in
     * the course of its life: the units leaving when a Courier takes it, the
     * units coming back when a Courier brings it back, and the write-off when
     * a User gives it up as dead. Each arm reads the parcel as it stands now
     * rather than the transition that woke it, so the order the tellings
     * arrive in cannot change what the ledger ends up holding.
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

        $this->recordDeparture($shipment);

        if ($shipment->status === ShipmentStatus::Returned) {
            $this->recordHomecoming($shipment);
        }

        /**
         * A parcel the Courier has finished with is not one to write off,
         * whatever a row says about who pressed what: it was delivered, or it
         * came back, and either way what became of the units is already on the
         * record. What is written off is a parcel still out there when a User
         * gave up on it, which is the only state the screen offers the button
         * in.
         */
        if ($shipment->abandoned_at !== null && ! $shipment->status->isTerminal()) {
            $this->recordWriteOff($shipment);
        }
    }

    /**
     * Record which of a half-arrived parcel's items came back, and put those
     * units — and only those — on the shelf again.
     *
     * The one ending the ledger cannot work out for itself, and therefore the
     * one arm of a parcel's life that is asked for rather than reconciled. A
     * Courier reports a collected amount and never a list of items (ADR-0021),
     * so a Shipment reaching Partly Delivered writes nothing at all and waits;
     * what a User names here is the only account of it there will ever be.
     *
     * The answer and its movements are written together or not at all, for
     * the reason a Supply and its movements are: a parcel marked answered that
     * put nothing back would be a question closed over a shelf that never
     * heard about it.
     *
     * Named lines rather than typed quantities, because nobody in this system
     * types a quantity. What goes back is read off the Sale movements this
     * parcel already wrote and never off the Order — so a Product nobody had
     * counted has nothing to bring back, a line naming more than left brings
     * back only what left, and what the units are worth is the figure stamped
     * on the row being answered rather than whatever the shelf has cost since
     * (ADR-0022).
     *
     * Naming nothing is a real answer and closes the question all the same: a
     * User who rings the Customer and learns that everything stayed with them
     * has established exactly that, and the ledger asserting no movement is
     * the honest record of it. That is also why the answer is a time and a
     * name on the parcel rather than the movements themselves — an answer of
     * nothing writes no movement to read it off.
     *
     * Answered once. The screen hides a question already answered, this
     * refuses it under the parcel's own lock against a fresh read, and the
     * movements' one-cause-one-movement key refuses it at the database.
     *
     * @param  list<int|string>  $orderLineIds  The Order Lines a User says came back.
     */
    public function recordPartialReturn(Shipment $shipment, array $orderLineIds, ?User $answeredBy = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $orderLineIds, $answeredBy): Shipment {
            /** Read back under the row's own lock: a screen is only as fresh as the moment it was drawn. */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());

            if (! $locked->isAwaitingAnswer()) {
                throw new ParcelNotAwaitingAnswer($locked);
            }

            $named = $this->unitsNamed($locked, $orderLineIds);

            foreach ($this->outstanding($locked) as [$departure, $units]) {
                $cameBack = min($named[$departure->product_id] ?? 0, $units);

                if ($cameBack <= 0) {
                    continue;
                }

                $this->writeOnce(
                    product: $departure->product,
                    quantity: $cameBack,
                    reason: StockMovementReason::PartialReturn,
                    source: $locked,

                    /** What that Sale was stamped with, so exactly the money that went out with these units comes back (ADR-0022). */
                    unitCost: $departure->unit_cost,

                    /**
                     * A name, unlike every other movement a parcel writes.
                     * Those are a Courier's doing and are recorded by a sweep
                     * with nobody signed in; this one exists because a person
                     * established something nothing else could, and a
                     * surprising figure is owed somebody to ask about it.
                     */
                    user: $answeredBy,
                );
            }

            $locked->fill([
                'answered_at' => now(),
                'answered_by' => $answeredBy?->getKey(),
            ])->save();

            return $locked;
        });
    }

    /**
     * How many units of each Product a User has named, keyed by Product, read
     * off the Order Lines they ticked.
     *
     * Summed per Product by `unitsPerProduct()`, where the reason for summing
     * is written down.
     *
     * Read with the Order trashed or not, like everything else a parcel is
     * settled from: somebody tidying the Order away does not unsay what a
     * User established about the box.
     *
     * @param  list<int|string>  $orderLineIds
     * @return array<int, int>
     */
    private function unitsNamed(Shipment $shipment, array $orderLineIds): array
    {
        $order = $shipment->order()->withTrashed()->first();

        if ($orderLineIds === [] || $order === null) {
            return [];
        }

        return $this->unitsPerProduct(
            $order->lines()->whereKey($orderLineIds)->whereNotNull('product_id')->toBase(),
            OrderLine::grams(),
        );
    }

    /**
     * The units in a parcel leaving, because a Courier has taken it.
     */
    private function recordDeparture(Shipment $shipment): void
    {
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

                /** The average in force as the box goes out of the door, read from the rows already written (ADR-0022). */
                unitCost: $this->averageCost($product),
            );
        }
    }

    /**
     * The units in a parcel coming back, because a Courier brought it back.
     *
     * A box came back through the door, so it is a Return — the word for that
     * and for nothing else. What a parcel given up as dead writes is an
     * Unsold, which is the same arithmetic and a different sentence.
     */
    private function recordHomecoming(Shipment $shipment): void
    {
        $this->putBack($shipment, StockMovementReason::Return);
    }

    /**
     * A parcel given up as dead, written off at what its units cost when they
     * left.
     *
     * Taken back off the sale and then lost, and both halves are meant. The
     * units left the shelf once, when the Courier took the box, and a
     * write-off that only deducted would take the same box a second time —
     * leaving On Hand below what is on the shelf for good, on a figure whose
     * whole worth is that it can be settled in an argument. So the parcel is
     * put back at what it left at and written off from there: what the pair
     * does to On Hand is nothing, because nothing has happened to a box that
     * had not already happened, and what it does to the record is name the
     * loss.
     *
     * Naming it is the point. Without the Loss the units read as an ordinary
     * Sale, indistinguishable from stock somebody paid for, and what a Courier
     * never collecting a parcel actually cost surfaces months later as
     * shrinkage nobody can account for.
     *
     * The put-back is an Unsold rather than a Return, which is what keeps this
     * right in the one order it could have gone wrong in: a parcel written off
     * and *then* brought back by the Courier owes a Return as well, and a
     * Return already written against this Shipment would swallow it as a
     * second telling and leave the units off the shelf for good. Two words,
     * two rows, and the Courier's news lands whenever it lands.
     */
    private function recordWriteOff(Shipment $shipment): void
    {
        /**
         * Read once, before anything is written, and read the same way
         * `putBack()` reads it below — the pair has to be the same units on
         * both sides of itself or the write-off moves On Hand, which is the
         * one thing it must not do. Today that is simply everything the
         * parcel took, because a half-arrived parcel is terminal and so is
         * never written off; this is written this way so that it stays a pair
         * if that ever stops being true.
         */
        $writtenOff = $this->outstanding($shipment);

        $this->putBack($shipment, StockMovementReason::Unsold);

        foreach ($writtenOff as [$departure, $units]) {
            $this->writeOnce(
                product: $departure->product,

                /** Out again, and this time for nothing: the same units, at the same cost, as a loss. */
                quantity: -$units,

                reason: StockMovementReason::Loss,
                source: $shipment,
                unitCost: $departure->unit_cost,
            );
        }
    }

    /**
     * Put a parcel's units back where they came from, under the word for why.
     *
     * Read off the movements this parcel already wrote rather than off the
     * Order it was for, which is what makes it exact in all three ways that
     * matter. What goes back is what left, so a Product nobody had counted
     * when the parcel went out has nothing to put back — the box was never
     * taken off a shelf it was not on. How many go back is the number that
     * left, however the Order has been edited since. And what they are worth
     * is the figure stamped on the row being answered (ADR-0022): a Supply
     * that arrived while the parcel was out has moved the average, and these
     * units cost what they cost on the day they left, so the money that went
     * out with them is exactly the money that comes back.
     *
     * What left is read as what is still **outstanding** on it, which is the
     * same number every time except after a half-arrived parcel was answered:
     * a Courier turning up afterwards with the whole box owes the rest of it,
     * and putting all of it back would shelve the answered units twice.
     */
    private function putBack(Shipment $shipment, StockMovementReason $reason): void
    {
        foreach ($this->outstanding($shipment) as [$departure, $units]) {
            $this->writeOnce(
                product: $departure->product,

                /** Back in: the opposite of the row it answers, less whatever a User has already said came back. */
                quantity: $units,

                reason: $reason,
                source: $shipment,
                unitCost: $departure->unit_cost,
            );
        }
    }

    /**
     * What this parcel took off the shelf and nothing has yet put back: its
     * Sale movements, each with the units still outstanding on it.
     *
     * Ordinarily that is the whole of what left, and every arm reads it as
     * such. It is less only where a User has already answered a half-arrived
     * parcel, and then the arithmetic matters: a Courier turning up weeks
     * later with the box owes a Return for the rest, and a Return for all of
     * it would put units on the shelf that are on it already.
     *
     * A Product whose units have all come back drops out rather than writing
     * a movement of nothing — there is nothing to say about a box that is
     * already home.
     *
     * @return list<array{StockMovement, int}>
     */
    private function outstanding(Shipment $shipment): array
    {
        $alreadyBack = $this->alreadyBack($shipment);

        $rows = [];

        foreach ($this->departures($shipment) as $departure) {
            /** The departure is out and so negative; what is owed back is positive. */
            $units = -$departure->quantity - ($alreadyBack[$departure->product_id] ?? 0);

            if ($units > 0) {
                $rows[] = [$departure, $units];
            }
        }

        return $rows;
    }

    /**
     * How many units of each Product a User has already said came back off
     * this parcel, keyed by Product.
     *
     * Only the Partial Returns, and deliberately. A Return and an Unsold are
     * each the whole of what was left outstanding, so counting either here
     * would leave nothing outstanding at all — and the case that breaks is
     * the one the Unsold exists for: a parcel written off and *then* brought
     * back by the Courier owes a Return, and an Unsold counted as units
     * already home would swallow it and leave the box off the shelf for good.
     * A User naming lines is the only thing that brings a parcel home in
     * part, so it is the only thing counted.
     *
     * @return array<int, int>
     */
    private function alreadyBack(Shipment $shipment): array
    {
        return $this->unitsPerProduct(
            StockMovement::query()
                ->whereMorphedTo('source', $shipment)
                ->where('reason', StockMovementReason::PartialReturn)
                ->toBase(),
            'quantity',
        );
    }

    /**
     * How many grams each Product accounts for across a set of rows, keyed by
     * Product.
     *
     * The one shape three questions share: what an Order's lines carry, what
     * the lines a User ticked carry, and what a parcel has already put back.
     * Each of them is "so many grams of this Product" read off rows carrying a
     * `product_id` and a weight — a movement's own `quantity`, or an Order
     * Line's weight read through `OrderLine::grams()`, the one place that says
     * how much a line weighs — and each of them is summed per Product
     * rather than taken a row at a time — because the ledger's key is one
     * cause, one Product, one direction, and an Order naming the same Product
     * twice is still one Product in one box.
     *
     * Handed a query already dropped to the base builder, so the caller says
     * which rows and this says only how they are counted.
     *
     * @param  QueryBuilder  $rows  Rows carrying `product_id` and a weight.
     * @param  Expression|string  $grams  What one row weighs, in grams: an expression or a column.
     * @return array<int, int>
     */
    private function unitsPerProduct(QueryBuilder $rows, Expression|string $grams): array
    {
        $grammar = $rows->getGrammar();
        $weight = $grams instanceof Expression ? $grams->getValue($grammar) : $grammar->wrap($grams);

        return $rows
            ->selectRaw("product_id, sum({$weight}) as units")
            ->groupBy('product_id')
            ->pluck('units', 'product_id')
            ->map(fn (mixed $units): int => (int) $units)
            ->all();
    }

    /**
     * What this parcel actually took off the shelf: the Sale movements it
     * wrote, with their Products loaded.
     *
     * The ledger's own memory of the box, and the only honest account of it
     * once a parcel is over. Everything a parcel owes after it leaves is
     * worked out from these rather than from the Order — which can be edited,
     * trashed, or hold Products that were never in the ledger at all.
     *
     * @return Collection<int, StockMovement>
     */
    private function departures(Shipment $shipment): Collection
    {
        return StockMovement::query()
            ->whereMorphedTo('source', $shipment)
            ->where('reason', StockMovementReason::Sale)
            ->with('product')
            ->get();
    }

    /**
     * How many units of each Product this parcel carries, keyed by Product,
     * read off the Order the attempt was written for.
     *
     * Summed per Product rather than taken a line at a time, by
     * `unitsPerProduct()`. The Order's shape is fixed at Capture with a line
     * per Product, so this is the same figure either way — it is written this
     * way so that it stays the same figure if that ever stops being true.
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

        return $this->unitsPerProduct($order->lines()->whereNotNull('product_id')->toBase(), OrderLine::grams());
    }

    /**
     * Record what is on the shelf now, and square the ledger with it.
     *
     * The count and its Adjustment are written together or not at all: a
     * count with no movement behind it is a shelf somebody looked at and a
     * figure that never heard about it. The Adjustment's quantity is the
     * difference between what was counted and what the ledger holds at the
     * moment of writing — worked out here, so a parcel booked while the dialog
     * was open is measured against rather than lost (ADR-0040).
     *
     * What was counted is in grams (ADR-0035); the dialog converts the packets
     * and kilograms a Merchant types.
     *
     * A Product nobody has counted holds an unknown rather than zero, and the
     * first count is measured against zero all the same: what it finds is the
     * opening balance, recorded as such and carrying no cause — an opening
     * count is a fact the ledger saw, never a reason somebody picked.
     *
     * A count that agrees with the ledger writes an Adjustment of zero rather
     * than nothing, and carries no cause because nothing happened to explain.
     * It is the record that somebody looked and the ledger was right — and on
     * a Product counted for the first time it is also what puts a genuinely
     * empty shelf into the ledger, which nothing else could distinguish from a
     * shelf nobody has visited.
     *
     * Anywhere the figure moves, the cause is compulsory and must go the way
     * the change went (`AdjustmentCauseRefused`).
     */
    public function recordCount(
        Product $product,
        int $counted,
        ?AdjustmentCause $cause = null,
        ?string $note = null,
        ?User $countedBy = null,
    ): StockCount {
        return $this->square($product, fn (?int $onHand): int => $counted, $cause, $note, $countedBy);
    }

    /**
     * Add or remove a known number of grams: the change as the Merchant knows
     * it — two packets broke, five turned up behind the counter.
     *
     * Applied as a change, against what is held at the moment of writing, and
     * written as one ordinary Adjustment of exactly the delta — immutable and
     * cost-stamped at the average, because a change is a count with the total
     * worked out rather than a new kind of thing (ADR-0021).
     *
     * Refused three ways before the cause is even asked about:
     *
     * - A delta of nothing, which is not a change.
     *
     * - A Product nobody has counted (`StockNotCounted`), either way. An
     *   unknown has nothing to change from, so a first figure is always what is
     *   there now (ADR-0040).
     *
     * - Taking what is held below nothing (`AdjustmentBelowZero`). Below zero
     *   is a figure the ledger may conclude but never one a Merchant may assert
     *   by hand; a shelf genuinely emptier than the ledger thinks is answered
     *   by saying what is there now.
     */
    public function recordAdjustment(
        Product $product,
        int $delta,
        ?AdjustmentCause $cause,
        ?string $note = null,
        ?User $adjustedBy = null,
    ): StockCount {
        if ($delta === 0) {
            throw new InvalidArgumentException('An adjustment of nothing is not an adjustment. A change carries a signed, non-zero delta.');
        }

        return $this->square($product, function (?int $onHand) use ($product, $delta): int {
            if ($onHand === null) {
                throw new StockNotCounted($product);
            }

            if ($onHand + $delta < 0) {
                throw new AdjustmentBelowZero($product, $onHand, $delta);
            }

            return $onHand + $delta;
        }, $cause, $note, $adjustedBy);
    }

    /**
     * Write a Stock Count and its Adjustment, the figure counted being worked
     * out from what is held by whichever way the Merchant said it.
     *
     * The Product's row is locked first, so two adjustments of one Product
     * serialise — the same lock Capture's stock check takes — and On Hand is
     * read once, under it, for both the figure and the difference.
     *
     * @param  Closure(?int): int  $counted
     */
    private function square(
        Product $product,
        Closure $counted,
        ?AdjustmentCause $cause,
        ?string $note,
        ?User $by,
    ): StockCount {
        return DB::transaction(function () use ($product, $counted, $cause, $note, $by): StockCount {
            Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->first();

            $onHand = $this->onHand($product);
            $figure = $counted($onHand);
            $difference = $figure - ($onHand ?? 0);
            $opening = $onHand === null;

            if ($opening && $cause !== null) {
                throw new AdjustmentCauseRefused($product, $difference, $cause, opening: true);
            }

            if ($difference !== 0 && ! $opening && ($cause === null || ! $cause->fits($difference))) {
                throw new AdjustmentCauseRefused($product, $difference, $cause, opening: false);
            }

            $count = StockCount::create([
                'product_id' => $product->getKey(),
                'quantity' => $figure,

                /** Nothing to explain where nothing moved, and a first figure is explained by being first. */
                'adjustment_cause_id' => $difference === 0 ? null : $cause?->getKey(),
                'is_opening' => $opening,
                'note' => $note,
                'user_id' => $by?->getKey(),
            ]);

            $this->write(
                product: $product,
                quantity: $difference,
                reason: StockMovementReason::Adjustment,
                source: $count,
                user: $by,

                /** The average in force at this instant: units found or lost are worth what everything else on that shelf is. */
                unitCost: $this->averageCost($product),
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
     *
     * The insert runs inside a savepoint whenever a transaction is open:
     * PostgreSQL aborts the whole transaction on a refused insert, so
     * without one every statement after the harmless refusal fails.
     */
    private function writeOnce(
        Product $product,
        int $quantity,
        StockMovementReason $reason,
        Model $source,
        ?int $unitCost,

        /**
         * Nobody's hand by default. A User may have pressed a button
         * somewhere, but what moved these units was a Courier — and the same
         * movement is written by a sweep at four in the morning with nobody
         * signed in at all.
         *
         * A Partial Return is the one exception and the reason this is a
         * parameter: nothing but a person could have said which items came
         * back, so the row carries whoever did.
         */
        ?User $user = null,
    ): void {
        try {
            StockMovement::query()->withSavepointIfNeeded(fn () => $this->write(
                product: $product,
                quantity: $quantity,
                reason: $reason,
                source: $source,
                unitCost: $unitCost,
                user: $user,
            ));
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
     *
     * What to stamp is decided by the arm above that knows, and is stamped
     * here exactly as it is given, null included. Three answers are in use and
     * each is the only right one for its arm: a Supply says what it paid,
     * because the average is worked out from rows like it; a parcel leaving
     * and a count being squared take the average in force at this instant; and
     * a reversal — a Void, a Return, a Loss — takes the figure off the row it
     * answers, so exactly the money that went one way comes back the other.
     * Null is one of those answers rather than a missing one: a movement
     * written before anything was ever bought carries no cost, and a reversal
     * of it must carry none either, or money nothing recorded paying would
     * enter the ledger on the way back.
     */
    private function write(
        Product $product,
        int $quantity,
        StockMovementReason $reason,
        Model $source,
        ?User $user,
        ?int $unitCost,
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'reason' => $reason,

            /**
             * What a unit was worth as it moved, stamped here and never
             * recomputed (ADR-0022) — so a Supply recorded tomorrow moves
             * what tomorrow's movements cost and can never move what this one
             * says.
             *
             * Null where nothing bought had ever been recorded when this
             * moved, which is what every movement written before the first
             * Supply carries. A weighted average of nothing bought is not
             * zero; it is no figure at all, and a Product with no known cost
             * must read as unknown rather than present itself as infinitely
             * profitable.
             */
            'unit_cost' => $unitCost,

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

    /**
     * The last cost stamped on each of a set of Products, keyed by Product id,
     * in a constant number of queries — the batched sibling of
     * `lastStampedCost()`, reached only for the Products `averageCostFor()`
     * found emptied.
     *
     * The latest standing costed movement per Product is its highest id among
     * them, which `latest('id')` picks one Product at a time; here they are
     * gathered in one grouped query and their costs read back in a second, so
     * the fallback stays constant however many Products need it.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function lastStampedCostFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $latest = StockMovement::query()
            ->standing()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('unit_cost')
            ->toBase()
            ->groupBy('product_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        return StockMovement::query()
            ->whereIn('id', $latest)
            ->toBase()
            ->pluck('unit_cost', 'product_id')
            ->map(fn (mixed $cost): int => (int) $cost)
            ->all();
    }
}
