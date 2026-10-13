<?php

namespace App\Support\Inventory;

use App\Enums\OrderStatus;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many grams of a Product are already spoken for.
 *
 * Deliberately not part of `StockLedger`, and deliberately not a movement.
 * Committed is a fact about Orders rather than about a warehouse — nothing
 * has happened to a box — so it is computed from the Orders every time it is
 * asked for, drawn beside On Hand and never taken off it (ADR-0021).
 * Reserving stock at Confirmed was considered and rejected for exactly this
 * reason: a reservation that is also a movement makes On Hand mean two things
 * at once.
 *
 * What it counts is the Confirmed Orders nobody has sent yet. The glossary
 * says "with no Active Shipment", and that phrase has to be read the way
 * `Order::notYetSent()` already reads it: every delivered Order has no Active
 * Shipment either, and counting those would leave a Merchant's Committed
 * figure climbing forever with units that left months ago. A parcel that came
 * back is spoken for again, which is the same answer the Orders queue gives
 * about it.
 */
class CommittedStock
{
    /**
     * What a query loaded by `withCommitted()` carries the figure under.
     * Named once, because a table column, a sort and a test all have to spell
     * it the same way — the reason `StockLedger::ON_HAND` is named once.
     */
    public const COMMITTED = 'committed';

    /**
     * Load what is spoken for onto every Product a query returns, under
     * `self::COMMITTED`.
     *
     * Summed into the query the list already runs, beside On Hand and Value
     * at Cost, so a catalogue of any size costs one query rather than one per
     * row.
     *
     * A Product nothing is waiting on sums to null, and the column draws that
     * as nothing spoken for rather than as an unknown. That is the opposite
     * of what On Hand does with a null, and the difference is the point:
     * On Hand is unknown until somebody counts a shelf, where this is read
     * off the Orders themselves and is therefore always known. Nothing
     * committed is a fact, not a gap.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function withCommitted(Builder $products): Builder
    {
        return $products->withSum(
            ['orderLines as '.self::COMMITTED => fn (Builder $query): Builder => $query->whereHas(
                'order',
                fn (Builder $orders): Builder => $orders
                    /** The relation reads trashed Orders so a line keeps its parent; an Order somebody deleted is not one to keep stock for. */
                    ->withoutTrashed()
                    ->where('status', OrderStatus::Confirmed)
                    ->notYetSent(),
            )],
            /** Each line's weight read where the ledger reads it, so what is spoken for and what leaves are the same grams. */
            OrderLine::grams(),
        );
    }
}
