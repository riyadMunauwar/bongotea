<?php

namespace App\Support\Inventory;

use App\Models\Product;
use LogicException;

/**
 * A Decrease may not take what is held below nothing.
 *
 * A figure below zero is a real reading — a parcel booked for stock nobody
 * recorded arriving leaves On Hand below nothing, asking to be explained
 * (ADR-0021) — but it is only ever a thing the ledger *concludes*, never one a
 * Merchant *asserts* by hand. Removing more than is held is either a miscount
 * or a shelf genuinely emptier than the ledger thinks, and both are answered by
 * counting the shelf rather than by driving the figure negative on purpose.
 *
 * Carries what was held and the delta refused, so a screen can say "you have
 * three, that removes five" and nudge toward saying what is there now.
 *
 * A `LogicException` for the reason `StockNotCounted` is one: it names a thing
 * that may not be done, not a failure on the way to doing it.
 */
class AdjustmentBelowZero extends LogicException
{
    public function __construct(
        public readonly Product $product,
        public readonly int $onHand,
        public readonly int $delta,
    ) {
        parent::__construct(
            'Decreasing product '.$product->getKey().' by '.abs($delta)." would take On Hand from {$onHand} below zero. Set an exact count instead.",
        );
    }
}
