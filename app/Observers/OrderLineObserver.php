<?php

namespace App\Observers;

use App\Models\OrderLine;

class OrderLineObserver
{
    /**
     * A line's total is always quantity times the price snapshotted at Capture.
     *
     * Computed here rather than at each call site so there is exactly one
     * answer to "what does this line cost" — and so editing a quantity in the
     * admin cannot pick up the Product's *current* price, which is the bug the
     * snapshot exists to close.
     */
    public function saving(OrderLine $line): void
    {
        $line->line_total = $line->unit_price * $line->quantity;
    }

    public function saved(OrderLine $line): void
    {
        $line->order->recalculateSubtotal();
    }

    public function deleted(OrderLine $line): void
    {
        $line->order->recalculateSubtotal();
    }
}
