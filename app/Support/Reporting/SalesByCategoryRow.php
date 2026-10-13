<?php

namespace App\Support\Reporting;

use App\Models\Category;

/**
 * One Category's share of a window's selling: the Orders that held it, what
 * its lines came to and what they weighed, each line counted under the
 * Category it was sold under. Beside them, the same figures over the
 * comparison window.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class SalesByCategoryRow
{
    public function __construct(
        /** The Category the lines were sold under; null once it has been deleted. */
        public ?Category $category,
        /** The Orders holding at least one of its lines, not Cancelled. */
        public int $orders,
        /** Its lines' totals: goods alone, since the Delivery Charge belongs to no Category. */
        public int $sales,
        public int|float $kilograms,
        public int $previousSales,
        public int|float $previousKilograms,
    ) {}

    public function name(): string
    {
        return $this->category->name ?? 'Deleted Category';
    }

    /**
     * Its Kilograms as a share of every Category's, from 0 to 1; null where
     * nothing was weighed at all.
     */
    public function kilogramsShareOf(int|float $kilograms): ?float
    {
        return $kilograms == 0 ? null : $this->kilograms / $kilograms;
    }
}
