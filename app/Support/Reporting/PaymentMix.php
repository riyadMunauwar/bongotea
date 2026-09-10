<?php

namespace App\Support\Reporting;

/**
 * What the Payment Method mix bar chart draws: one row per Payment Method
 * the Orders in the window recorded, so a Merchant can see whether a
 * gateway is earning its fee. A method no Order in the window chose is
 * not here — the chart compares the methods that were used, and a
 * Merchant reads absence as "nobody paid that way". The widget labels
 * and draws; it never counts.
 */
final readonly class PaymentMix
{
    public function __construct(
        public ReportingWindow $window,
        /**
         * In the enum's order, Cash on Delivery first, so the bars stand in
         * the same order on every window.
         *
         * @var list<PaymentMixRow>
         */
        public array $rows,
    ) {}

    /**
     * One figure per row, for a chart's dataset.
     *
     * @param  callable(PaymentMixRow): (int|float|string)  $figure
     * @return list<int|float|string>
     */
    public function series(callable $figure): array
    {
        return array_map($figure, $this->rows);
    }
}
