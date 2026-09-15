<?php

namespace App\Support\Reporting;

/**
 * What the Stock report draws: a row per Product with what it holds and what
 * that is worth, and the money held altogether.
 *
 * **It takes no Reporting Window, and that is a decision rather than an
 * omission.** Every other result in this module is counted over a span of
 * days; this one answers what is on the shelf as things stand, and there is
 * no span behind that. So the screen that draws it offers no window control
 * at all rather than one that changes nothing — the reading `ReportsCluster`
 * recorded before there was anything to apply it to.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class StockHoldings
{
    /**
     * The holdings, with the money held worked out from the rows themselves.
     *
     * A total is a fact about the rows, so it is arrived at here rather than
     * handed in: nobody can construct a `StockHoldings` whose total disagrees
     * with what it is the total of.
     *
     * @param  list<StockHoldingRow>  $rows
     */
    public static function of(array $rows): self
    {
        /** Over the Products whose worth is known, and unknown where none of them is: an unknown is never added in as a nought. */
        $known = array_filter(
            array_map(fn (StockHoldingRow $row): ?int => $row->valueAtCost, $rows),
            fn (?int $value): bool => $value !== null,
        );

        return new self($rows, $known === [] ? null : array_sum($known));
    }

    private function __construct(
        /**
         * The most capital first, then the most units, then by name, so two
         * Products holding the same read in a stable order. A Product whose
         * worth is unknown sorts below every Product whose worth is known —
         * it is not worth nothing, it is a shelf nobody has priced, and the
         * top of this list is where the money is.
         *
         * @var list<StockHoldingRow>
         */
        public array $rows,

        /**
         * What everything held comes to, over the Products whose worth is
         * known — and null where none of them is.
         *
         * Unknown rather than nothing, for the reason a row's own figure is:
         * an installation where nobody has recorded buying anything has not
         * established that it holds nothing, and a nought in a money column
         * would say it had. A Product with no known worth is simply not in
         * the sum; it is not counted as a zero.
         */
        public ?int $valueHeld,
    ) {}

    /**
     * How many of the rows the ledger actually knows about — the Products
     * somebody has counted or had a delivery recorded for.
     *
     * Read beside the number of rows, it says how many of the Products on this
     * report the total held is drawn over, which is the one thing a money
     * figure over a partly-adopted ledger cannot say for itself. Adoption here
     * is deliberately gradual: a Merchant counts the five Products that matter
     * this afternoon and the rest sit unknown until somebody cares.
     */
    public function counted(): int
    {
        return count(array_filter($this->rows, fn (StockHoldingRow $row): bool => $row->onHand !== null));
    }
}
