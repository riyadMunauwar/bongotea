<?php

namespace App\Support\Inventory;

use App\Models\Product;
use LogicException;

/**
 * Units cannot be removed from a Product the ledger has never seen.
 *
 * A Decrease takes so many units off what is held, but a Product nobody has
 * counted holds an unknown rather than a figure — there is nothing on record
 * to take from, and letting a Decrease invent one would turn that unknown into
 * a figure below nothing that no shelf agrees with (ADR-0021). The Product is
 * adopted first: an Increase, or a Set exact count, puts a first figure into
 * the ledger the way an Opening count does.
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
            "Product {$product->getKey()} has never been counted, so there is no stock to decrease. Increase it or set an exact count first.",
        );
    }
}
