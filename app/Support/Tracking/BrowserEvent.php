<?php

namespace App\Support\Tracking;

use App\Models\Order;

/**
 * One Provider event the browser is told to report, and what it reports it
 * about.
 *
 * The browser half of what `TrackingEvent` is for the server half: the event
 * under the Provider's own name, the deduplication id both copies carry so
 * the Provider counts one, and the Order the parameters are read from at the
 * moment the snippet is written.
 *
 * The deduplication id is carried rather than derived from the Order, for
 * the reason the outbox carries a column for it: what the two halves agree
 * on is a string, and an event that is about no Order at all still has to
 * agree on one.
 */
final class BrowserEvent
{
    private function __construct(
        public readonly string $event,
        public readonly string $deduplicationId,
        public readonly Order $order,
    ) {}

    /**
     * An event about an Order, deduplicated on the Order's number — the one
     * string the browser and the server can both reach without coordinating,
     * and the one the outbox row already carries.
     */
    public static function forOrder(string $event, Order $order): self
    {
        return new self($event, $order->order_number, $order);
    }
}
