<?php

namespace App\Observers;

use App\Models\Product;
use App\Support\PageCache\PageCacheInvalidator;

/**
 * A Product is rendered into every Page that links it, so a price or name
 * change has to clear all of them. Walking the pivot is what stands in for the
 * cache tags the file and database drivers do not have.
 */
class ProductObserver
{
    public function __construct(private readonly PageCacheInvalidator $invalidator) {}

    public function saved(Product $product): void
    {
        $this->invalidator->forgetPagesFor($product);
    }

    /**
     * The soft-delete case from the domain model: linked Pages keep rendering,
     * with the checkout gone. That only happens if their cached copies go.
     */
    public function deleted(Product $product): void
    {
        $this->invalidator->forgetPagesFor($product);
    }

    public function restored(Product $product): void
    {
        $this->invalidator->forgetPagesFor($product);
    }

    /**
     * `forceDeleting`, not `forceDeleted`: the pivot rows cascade away with the
     * product row, so after the delete there is no longer a list of pages to
     * walk.
     */
    public function forceDeleting(Product $product): void
    {
        $this->invalidator->forgetPagesFor($product);
    }
}
