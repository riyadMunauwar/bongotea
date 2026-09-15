<?php

namespace App\Support\Reporting;

use App\Models\Product;

/**
 * One line of the Margin report: a Product, what its Order Lines in the
 * window earned, and what the units on those Lines were stamped as costing.
 *
 * Revenue here is the Lines' own totals and carries **no Delivery Charge**.
 * The charge belongs to the Order rather than to any Product on it, and
 * splitting it across the Lines would be an invention; it is carried whole on
 * `Margin` instead, where it joins revenue so the report's own total agrees
 * with `Sales`.
 *
 * Cost is nullable and the difference from zero is the point of the column: a
 * Product nothing has been recorded buying stamps no cost at Capture
 * (ADR-0022), and a margin read off a nought would present it as infinitely
 * profitable. That is the same reading On Hand takes against `0`, and the
 * Courier figures before it.
 *
 * Partly costed is a third state and is drawn as one. Some of these units were
 * stamped and some were not — Orders captured before costs were recorded at
 * all, most often — so the figure is the stamped part and the row says the
 * rest is missing rather than quietly presenting a margin that is too high.
 *
 * The Product is carried whole, trashed or not, exactly as `ProductRankingRow`
 * carries it, and is null once it is deleted for good — the recorded name is
 * what survives that, and is what the row is read by.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class MarginRow
{
    public function __construct(
        /** The Product the Lines refer to, trashed included; null once it is deleted for good. */
        public ?Product $product,
        /** The name the Lines recorded at Capture: the attribution that survives a hard delete. */
        public string $recordedName,
        /** Units sold: the quantities of every Line, over the Orders not Cancelled. */
        public int $units,
        /** The Lines' totals, which is what those units earned. Delivery Charge is not in it. */
        public int $revenue,
        /** What the stamped units cost, and null where not one of them was stamped. */
        public ?int $cost,
        /** How many of the units carried a stamped cost at all. */
        public int $costedUnits,
    ) {}

    /**
     * What the row is called, read exactly as the Top Products ranking reads
     * it: the Product's name while it is still sold, and the name its Lines
     * recorded once it is deleted — either kind of deleted.
     */
    public function name(): string
    {
        return $this->product === null || $this->product->trashed()
            ? $this->recordedName
            : $this->product->name;
    }

    /**
     * Whether the Product has been withdrawn from the catalogue since these
     * Lines were captured. It stays on the report either way: it earned what
     * it earned inside the window, and retiring it afterwards does not unearn
     * it.
     */
    public function isDeleted(): bool
    {
        return $this->product?->trashed() ?? false;
    }

    /**
     * What is left of the revenue once the stamped cost is taken off it, and
     * **null where no cost is known at all**.
     *
     * Unknown rather than the whole revenue, which is what a zero cost would
     * quietly make it. A Product nobody has recorded buying is not a Product
     * that costs nothing to sell.
     */
    public function margin(): ?int
    {
        return $this->cost === null ? null : $this->revenue - $this->cost;
    }

    /**
     * Margin as a share of revenue, from 0 to 1 — the figure that ranks a
     * Product against one ten times its size, which ranking by money alone
     * cannot do.
     *
     * Null wherever the margin is, and null again where the units earned
     * nothing at all: a rate with no denominator is unknown rather than zero,
     * which is the reading `HasSalesRatios::conversionRate()` already takes of
     * a Page whose Visits are not counted. A nought would be worse than
     * unhelpful here — a line given away at a stamped cost really did lose
     * money, and a 0.0% in the column a Merchant ranks by would read as break
     * even.
     */
    public function marginRate(): ?float
    {
        $margin = $this->margin();

        if ($margin === null || $this->revenue === 0) {
            return null;
        }

        return $margin / $this->revenue;
    }

    /**
     * Whether any unit on this row was sold without a cost stamped on it —
     * either all of them, in which case the cost beside it is null, or some of
     * them, in which case the cost is the part that was stamped and is
     * therefore too low.
     *
     * A screen says so where it is true. A margin drawn over a cost missing
     * half its units is not wrong by a little; it is the failure ADR-0022
     * exists to keep visible.
     */
    public function hasUnknownCost(): bool
    {
        return $this->costedUnits < $this->units;
    }
}
