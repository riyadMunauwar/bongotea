<?php

namespace App\Support\Inventory;

use App\Models\Product;

/**
 * One Product whose shelf will not outlast the rate it is going out at, and
 * the figure that put it there.
 *
 * A named pair rather than a tuple, for the reason every row in the reporting
 * module is one: the two travel together everywhere — the to-do list, the
 * screens that draw it — and `$row[0]`/`$row[1]` at each of those sites is a
 * reader having to remember which way round they were packed.
 */
final readonly class ThinShelf
{
    public function __construct(
        public Product $product,

        /** Days of Cover as it stood when the list was read; never null, because an unknown cover is not a thin one. */
        public float $daysOfCover,
    ) {}
}
