<?php

namespace App\Support\Reporting;

/**
 * What the Delivery mix table draws: what each Delivery Option collected in
 * the window, and where the Orders went, so a Merchant can judge delivery
 * pricing against courier coverage.
 *
 * Both halves read the Orders that are not Cancelled: the figures are what
 * was actually to be delivered and collected, not what was asked for and
 * called off. That is narrower than the glossary's Orders on purpose, and
 * it is why the column is a Delivery Charge collected rather than a
 * Delivery Charge quoted.
 */
final readonly class DeliveryMix
{
    public function __construct(
        public ReportingWindow $window,
        /**
         * Most Orders first, then by what was collected, then by label.
         *
         * @var list<DeliveryMixRow>
         */
        public array $rows,
        /**
         * The Districts the most Orders were bound for, at most five, most
         * Orders first; an Order that recorded no District is left out
         * rather than counted as a blank place.
         *
         * @var array<string, int>
         */
        public array $districts,
    ) {}
}
