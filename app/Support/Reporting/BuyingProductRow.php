<?php

namespace App\Support\Reporting;

use App\Models\Product;

/**
 * One line of the Buying report read by Product: what was bought of it inside
 * the Reporting Window, what that cost, and what a unit averaged.
 *
 * The average is the whole reason the report is worth reading this way round.
 * A Supplier row says where the money went; this one says what the money
 * bought, and a Merchant comparing one window's average against the next is
 * asking the question `CONTEXT.md` gives a Supplier a record for in the first
 * place — whether a price is moving.
 *
 * This average is **not** the Product's weighted average cost and must never
 * be read as one. That figure is `StockLedger::averageCost()`, blended over
 * every unit the ledger holds and stamped on movements as they happen
 * (ADR-0022); this one is what was paid over one span of days, over the
 * deliveries that stand and nothing else.
 *
 * The Product is carried whole, trashed included, and is never null:
 * `supply_lines.product_id` is restricted rather than cascading, so a Product
 * that was ever bought cannot be erased out from under what was paid for it.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class BuyingProductRow
{
    public function __construct(
        public Product $product,
        /** Units arrived inside the window, across every Supplier. */
        public int $units,
        /** What they cost: every line's quantity at the price that line was bought at. */
        public int $cost,
    ) {}

    public function name(): string
    {
        return $this->product->name;
    }

    /**
     * Whether this Product has been withdrawn from the catalogue since. It
     * stays on the report either way: money was spent on it inside the
     * window, and retiring it afterwards does not unspend that.
     */
    public function isDeleted(): bool
    {
        return $this->product->trashed();
    }

    /**
     * What one unit averaged over the window, in minor units — the cost
     * divided by the units, and null where no units arrived at all.
     *
     * Null rather than nought for the reason every other absent figure in
     * this module is: nothing bought has no price, and a zero in a money
     * column would say the Supplier gave it away.
     */
    public function averageUnitCost(): ?int
    {
        return $this->units === 0 ? null : (int) round($this->cost / $this->units);
    }
}
