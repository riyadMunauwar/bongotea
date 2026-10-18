<?php

namespace App\Support\Inventory;

use App\Models\AdjustmentCause;
use App\Models\Product;
use LogicException;

/**
 * An Adjustment whose cause does not explain the change it would write
 * (ADR-0040).
 *
 * Four ways, all the ledger's to refuse rather than a screen's to remember: a
 * figure that moves with no cause, a cause going the other way from the
 * change, a cause that has been retired, and any cause at all on an opening
 * count — which is a fact the ledger saw, never a reason somebody picked.
 *
 * Most often met as a race rather than a mistake: the shelf moved while the
 * dialog was open, so the change the cause was chosen for is not the change
 * there is now. A screen tells the Merchant so and lets them look again.
 */
class AdjustmentCauseRefused extends LogicException
{
    public function __construct(
        public readonly Product $product,
        public readonly int $difference,
        public readonly ?AdjustmentCause $cause,
        public readonly bool $opening,
    ) {
        parent::__construct(match (true) {
            $opening => "Product {$product->getKey()} is being counted for the first time, which is an opening count and carries no cause.",
            $cause === null => "Moving product {$product->getKey()} by {$difference} needs a cause.",
            default => "Cause {$cause->getKey()} cannot explain moving product {$product->getKey()} by {$difference}.",
        });
    }
}
