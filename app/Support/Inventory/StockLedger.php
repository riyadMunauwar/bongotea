<?php

namespace App\Support\Inventory;

use App\Enums\AdjustmentCause;
use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
 * It is deliberately narrow today. A User counts, and the difference is
 * squared away as an Adjustment. Supply, Shipment, Return and Loss each
 * arrive as another method here and nowhere else — in particular a
 * `ShipmentObserver` will call this rather than write rows of its own,
 * because status alone is written from four places in the courier layer and
 * that is four places the next Courier path could forget.
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
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'reason' => $reason,

            /**
             * What a unit was worth as it moved is the weighted average of
             * what has been supplied, stamped here and never recomputed
             * (ADR-0022) — and nothing has been supplied, because Supply does
             * not exist yet. A weighted average of nothing bought is not
             * zero; it is no figure at all, and a Product with no known cost
             * must read as unknown rather than present itself as infinitely
             * profitable. The average is read from the ledger at the moment
             * of stamping when there is one to read.
             */
            'unit_cost' => null,

            'user_id' => $user?->getKey(),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
    }
}
