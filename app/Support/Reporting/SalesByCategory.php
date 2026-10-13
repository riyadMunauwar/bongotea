<?php

namespace App\Support\Reporting;

use App\Support\Kilograms;

/**
 * Sales and Kilograms Sold read once per Category, most Kilograms first.
 *
 * A Category's money is its lines alone. The Delivery Charge belongs to an
 * Order and to no Category on it, so it is carried whole beside the rows and
 * the Categories add up to Sales less it — said on the screen rather than
 * split among them by a rule nobody charged by.
 */
final readonly class SalesByCategory
{
    public function __construct(
        public ReportingWindow $window,
        /** @var list<SalesByCategoryRow> */
        public array $rows,
        /** The Delivery Charges of the same Orders, which no Category holds. */
        public int $deliveryCharge,
    ) {}

    /**
     * Every Category's Kilograms together: the window's Kilograms Sold.
     */
    public function kilograms(): int|float
    {
        return Kilograms::sum(array_map(fn (SalesByCategoryRow $row): int|float => $row->kilograms, $this->rows));
    }
}
