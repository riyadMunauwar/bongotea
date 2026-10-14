<?php

namespace App\Support\Inventory;

use App\Enums\OrderStatus;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 *
 * Free to Sell is answered here too, for the same reason: it is On Hand less
 * what Orders hold, and what Orders hold is this module's question. It is the
 * figure Capture refuses an Order against (ADR-0037).
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
     * What a query loaded by `withFreeToSell()` carries the figure under,
     * named once for the same reason.
     */
    public const FREE_TO_SELL = 'free_to_sell';

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

    /**
     * How many grams of a Product can still be ordered, or null where nobody
     * has ever counted it (ADR-0037).
     *
     * Read through the same query `withFreeToSell()` loads a list with, so the
     * one figure and the list's column cannot answer two ways.
     */
    public function freeToSell(Product $product): ?int
    {
        return $this->freeToSellFor([$product->getKey()])[$product->getKey()] ?? null;
    }

    /**
     * Free to Sell for several Products in one query, keyed by Product id — the
     * shape Capture reads a whole basket in. A Product taken out of the
     * catalogue is read too, since its Orders still hold its weight.
     *
     * @param  list<int>  $productIds
     * @return array<int, int|null>
     */
    public function freeToSellFor(array $productIds): array
    {
        return $this->withFreeToSell(Product::withTrashed()->whereKey($productIds))
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => self::figure($product->getAttribute(self::FREE_TO_SELL)),
            ])
            ->all();
    }

    /**
     * Load Free to Sell onto every Product a query returns, under
     * `self::FREE_TO_SELL`: On Hand less the weight on every Order that is
     * neither Cancelled nor sent, New as well as Confirmed.
     *
     * A wider set than Committed on purpose. Committed is what the warehouse
     * owes to Orders somebody has phoned; this is what a Customer can still be
     * promised, and an Order nobody has phoned yet is still a promise somebody
     * believes. Like Committed it is a fact about Orders and never a movement,
     * so a cancelled Order frees its weight the instant it is cancelled, with
     * nothing to reverse.
     *
     * Null where nobody has counted the Product: On Hand is unknown there, and
     * unknown less anything is still unknown — which SQL's own null arithmetic
     * answers without being told.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function withFreeToSell(Builder $products): Builder
    {
        $held = StockMovement::query()
            ->toBase()
            ->selectRaw('sum(stock_movements.quantity)')
            ->whereColumn('stock_movements.product_id', $products->qualifyColumn('id'));

        $promised = OrderLine::query()
            ->selectRaw('coalesce(sum('.OrderLine::grams()->getValue(DB::connection()->getQueryGrammar()).'), 0)')
            ->whereColumn('order_lines.product_id', $products->qualifyColumn('id'))
            ->whereHas('order', fn (Builder $orders): Builder => $orders
                ->withoutTrashed()
                ->whereIn('status', [OrderStatus::New, OrderStatus::Confirmed])
                ->notYetSent())
            ->toBase();

        if ($products->getQuery()->columns === null) {
            $products->select($products->getModel()->qualifyColumn('*'));
        }

        return $products->selectRaw(
            "({$held->toSql()}) - ({$promised->toSql()}) as ".self::FREE_TO_SELL,
            [...$held->getBindings(), ...$promised->getBindings()],
        );
    }

    /** A summed figure as it comes back from the database: a string on some drivers, null where nothing was counted. */
    private static function figure(mixed $grams): ?int
    {
        return $grams === null ? null : (int) $grams;
    }
}
