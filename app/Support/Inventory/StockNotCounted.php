<?php

namespace App\Support\Inventory;

use App\Models\Product;
use LogicException;

/**
 * A Product the ledger has never seen cannot be changed, either way.
 *
 * A change moves what is held, but a Product nobody has counted holds an
 * unknown rather than a figure — there is nothing on record to change from,
 * and letting a change invent one would turn that unknown into a figure no
 * shelf agrees with (ADR-0021). Its first figure is always what is there now,
 * recorded as an opening count (ADR-0040).
 *
 * A `LogicException` rather than a `RuntimeException`, because it names a thing
 * that cannot be done rather than a thing that went wrong on the way to doing
 * it — the same register as `SupplyAlreadyVoided` and `ParcelNotAwaitingAnswer`.
 */
class StockNotCounted extends LogicException
{
    public function __construct(public readonly Product $product)
    {
        parent::__construct(
            "Product {$product->getKey()} has never been counted, so there is nothing to change. Say what is there now first.",
        );
    }
}
