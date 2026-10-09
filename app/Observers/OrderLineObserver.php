<?php

namespace App\Observers;

use App\Models\OrderLine;

class OrderLineObserver
{
    /**
     * A line's total is always quantity times the price the line records —
     * set at Capture, or by the last Repricing — and never the Product's
     * current price read on the side.
     *
     * Computed here rather than at each call site so there is exactly one
     * answer to "what does this line cost".
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
