<?php

namespace App\Support\Reporting;

use App\Support\Kilograms;

/**
 * What the Margin report draws: what a Reporting Window actually earned
 * rather than what it turned over, read per Product and added up across
 * them.
 *
 * **Revenue here is `Sales`, to the minor unit.** Both halves of margin are
 * counted at Capture, over the Orders `Sales` itself counts — a cash-on-
 * delivery Order has no other moment — so the two screens can never give a
 * Merchant two answers for one span of days (ADR-0022). That is why revenue
 * carries the **Delivery Charge**: `Sales` does, the glossary says so, and a
 * revenue figure here that left it out would be a second revenue figure. The
 * charge belongs to an Order rather than to any Product on it, so it is
 * carried whole beside the rows instead of split across them, and the screen
 * says which figure includes it.
 *
 * A Cancelled Order is in none of it and leaves both sides together: what it
 * sold was never sold, and what it cost was never spent.
 *
 * **Cost is what was stamped, and unknown is not nothing.** A Product nothing
 * has been recorded buying stamps no cost at Capture, and an uncosted Line
 * adds nothing to the cost rather than a nought — so a window holding no
 * stamped cost at all reports unknown cost and unknown margin rather than a
 * margin of the whole revenue. How much of the window is costed at all is a
 * figure of its own here, for the reason the Stock report says how many
 * Products are in the ledger: a money figure over a ledger somebody is
 * halfway through adopting cannot say it for itself.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class Margin
{
    /**
     * The window's rows and its two whole-window figures, with everything
     * that is a fact about the rows worked out from the rows themselves.
     *
     * Revenue and the Delivery Charge are handed in because they are read
     * off the Orders rather than off their Lines — that is what makes revenue
     * `Sales` by construction instead of by a coincidence of arithmetic.
     * Everything else is counted here, so nobody can build a `Margin` whose
     * total disagrees with what it is the total of.
     *
     * @param  list<MarginRow>  $rows
     */
    public static function of(ReportingWindow $window, array $rows, int $revenue, int $deliveryCharge): self
    {
        $costed = array_filter($rows, fn (MarginRow $row): bool => $row->cost !== null);

        return new self(
            window: $window,
            rows: $rows,
            revenue: $revenue,
            deliveryCharge: $deliveryCharge,

            /** Unknown where not one unit in the window was stamped, and never a nought standing in for it. */
            cost: $costed === [] ? null : (int) array_sum(array_map(fn (MarginRow $row): int => (int) $row->cost, $costed)),
            units: Kilograms::sum(array_map(fn (MarginRow $row): int|float => $row->units, $rows)),
            costedUnits: Kilograms::sum(array_map(fn (MarginRow $row): int|float => $row->costedUnits, $rows)),
        );
    }

    private function __construct(
        public ReportingWindow $window,

        /**
         * The biggest margin first, then the biggest revenue, then by name,
         * so two Products that made the same read in a stable order. A
         * Product whose margin is unknown sorts below every Product whose
         * margin is known — it has not made nothing, nobody knows what it
         * made, and the top of this list is where the profit is.
         *
         * @var list<MarginRow>
         */
        public array $rows,

        /**
         * What the window earned: `Sales` over it, Delivery Charge included,
         * read off the very Orders the costs beneath were stamped on.
         */
        public int $revenue,

        /**
         * The Delivery Charge inside that revenue, carried separately because
         * it belongs to no Product and so appears on no row.
         */
        public int $deliveryCharge,

        /**
         * What the units sold were stamped as costing, and null where not one
         * of them was stamped at all.
         */
        public ?int $cost,

        /** Kilograms sold across every row: Kilograms Sold over the window, since the rows are every Line of the Orders `Sales` counts. */
        public int|float $units,

        /** How many of them carried a stamped cost. */
        public int|float $costedUnits,
    ) {}

    /**
     * What is left once the stamped cost is taken off `Sales` — the figure
     * the whole cost side exists for — and null where no cost is known.
     *
     * Unknown rather than the whole of revenue: an installation that has
     * recorded no buying has not established that its goods were free.
     */
    public function margin(): ?int
    {
        return $this->cost === null ? null : $this->revenue - $this->cost;
    }

    /**
     * Margin as a share of revenue, from 0 to 1. Null wherever the margin is,
     * and null again over a window that earned nothing — a rate with no
     * denominator is unknown rather than zero, and a window that gave away
     * costed goods lost money rather than breaking even. The row's own rate
     * reads the same way.
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
     * What the rows themselves earned, with no Delivery Charge in it.
     *
     * Beside `deliveryCharge` it is what `revenue` is made of, which is how a
     * screen can show the rows adding up to the figure above them while the
     * figure above them stays `Sales`.
     */
    public function goodsRevenue(): int
    {
        return (int) array_sum(array_map(fn (MarginRow $row): int => $row->revenue, $this->rows));
    }

    /**
     * Whether every unit sold in the window carried a stamped cost — which is
     * what decides whether the margin above may be read as a fact rather than
     * as a ceiling.
     */
    public function everythingCosted(): bool
    {
        return $this->costedUnits == $this->units;
    }
}
