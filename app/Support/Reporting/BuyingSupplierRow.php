<?php

namespace App\Support\Reporting;

use App\Models\Supplier;

/**
 * One line of the Buying report read by Supplier: who the goods came from,
 * how many deliveries they made inside the Reporting Window, how many units
 * arrived and what they cost.
 *
 * Deliveries is a count of Supplies and never of lines: one invoice is one
 * delivery however many Products it named, which is what makes "they deliver
 * to me four times a month" the figure a Merchant reads here.
 *
 * The Supplier is carried whole, trashed included, and is never null:
 * `supplies.supplier_id` is restricted rather than cascading, so somebody who
 * ever delivered anything cannot be erased out from under their own history —
 * taking them off the list is soft, and what they delivered goes on reading
 * under their name.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class BuyingSupplierRow
{
    public function __construct(
        public Supplier $supplier,
        /** Deliveries that stand: a Voided one is a delivery that did not happen. */
        public int $deliveries,
        /** Units arrived across those deliveries. */
        public int $units,
        /** What they cost: every line's quantity at the price that line was bought at. */
        public int $cost,
    ) {}

    public function name(): string
    {
        return $this->supplier->name;
    }

    /**
     * Whether this Supplier has been taken off the list since. They stay on
     * the report either way — what they delivered inside the window is a fact
     * about the Merchant's money, not about whether anybody still buys from
     * them.
     */
    public function isDeleted(): bool
    {
        return $this->supplier->trashed();
    }
}
