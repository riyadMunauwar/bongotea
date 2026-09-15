<?php

namespace App\Support\Reporting;

use App\Models\Product;

/**
 * One line of the Stock report: a Product, how many of it are held, and what
 * those units are worth at what they cost.
 *
 * Both figures are nullable and the difference from zero is the whole point.
 * A Product nobody has counted holds an **unknown**, not none — a zero is an
 * instruction to reorder, and a Product nobody has looked at is not a Product
 * that has run out. The same reading for its worth: a Product nothing has
 * been recorded buying is worth an unknown rather than nothing, which is what
 * keeps an uncounted shelf out of the total held. This is the third place the
 * codebase makes that choice, after a Courier's Balance and its Delivery Rate.
 *
 * Below nothing is legal on both, and is an unrecorded Supply announcing
 * itself: a parcel really can be booked against stock nobody recorded
 * arriving, and the honest reading of that is a figure that asks to be
 * explained rather than one quietly floored at zero.
 *
 * The Product is carried whole, trashed or not, so the screen can say it has
 * been withdrawn from the catalogue while it still holds units. It is never
 * null: `supply_lines.product_id` and the movements' own column are
 * restricted rather than cascading, so a Product that ever entered the ledger
 * cannot be erased out from under its own history.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class StockHoldingRow
{
    public function __construct(
        /** The Product this is about, trashed included. */
        public Product $product,
        /** Units held: the sum of the Product's movements, and null where it has none. */
        public ?int $onHand,
        /** What those units cost, summed off the movements themselves, and null where nothing costed has moved. */
        public ?int $valueAtCost,
    ) {}

    public function name(): string
    {
        return $this->product->name;
    }

    /**
     * Whether the Product has been withdrawn from the catalogue since. It is
     * still here, and has to be: units on a shelf are capital whether or not
     * anybody is still selling them, and leaving them out would make the
     * total held understate what the Merchant owns.
     */
    public function isDeleted(): bool
    {
        return $this->product->trashed();
    }

    /**
     * Whether this Product holds anything the report would lose by leaving it
     * out — which is what decides whether a withdrawn one stays on the list.
     *
     * An unknown is nothing held: a Product nobody ever counted and has since
     * retired never entered the ledger at all.
     */
    public function holdsSomething(): bool
    {
        return ($this->onHand ?? 0) !== 0 || ($this->valueAtCost ?? 0) !== 0;
    }
}
