<?php

namespace App\Support\Reporting;

use App\Models\Product;

/**
 * One line of the Top Products ranking: a Product and what its Order Lines
 * in the window came to. The Product is carried whole, trashed or not, so
 * the widget can say it is deleted; a Product deleted for good has only the
 * name its Lines recorded left, and the row carries that instead with no
 * Product at all — which is how history stays whole when a Product is
 * retired.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class ProductRankingRow
{
    public function __construct(
        /** The Product the Lines refer to, trashed included; null once it is deleted for good. */
        public ?Product $product,
        /** The name the Lines recorded at Capture: the attribution that survives a hard delete. */
        public string $recordedName,
        /** Units sold: the quantities of every Line, over the Orders not Cancelled. */
        public int $units,
        /** The Lines' totals, which is what those units earned. */
        public int $revenue,
    ) {}

    /**
     * What the row is called: the Product's name while it is still sold,
     * and the name its Lines recorded once it is deleted — either kind of
     * deleted. A retired Product is history, and history is read as it was
     * written; a live Product is read as it is now, so a row and the
     * Products screen never disagree about what is on sale.
     */
    public function name(): string
    {
        return $this->product === null || $this->product->trashed()
            ? $this->recordedName
            : $this->product->name;
    }

    /**
     * Whether the Product has been soft-deleted since these Lines were
     * captured. A hard-deleted Product is not "deleted" in this sense; it is
     * gone, and the row says so by having no Product.
     */
    public function isDeleted(): bool
    {
        return $this->product?->trashed() ?? false;
    }
}
