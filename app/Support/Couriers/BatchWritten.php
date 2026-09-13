<?php

namespace App\Support\Couriers;

use App\Models\Order;
use App\Models\Shipment;

/**
 * What a bulk booking committed: the parcels it wrote down to be dispatched,
 * and the Orders it deliberately left alone with the reason for each.
 *
 * The set that was written is the answer a User is given, which is why the
 * kinds of skip are kept apart rather than counted together. An Order that was
 * gone by the time the batch reached it is a fourth thing again: an Order that
 * should never have existed is not one to send, and restoring it first is the
 * answer. An Order
 * already holding an Active Shipment was never a candidate — sending it again
 * is the one thing a batch must never do. An Order whose delivery area the
 * Courier insists on and nothing could name is a parcel somebody has to look
 * at: it is not sent, and it is not silently sent to a guessed depot either
 * (ADR-0020).
 */
final class BatchWritten
{
    /**
     * @param  list<Shipment>  $written  the attempts committed, awaiting dispatch.
     * @param  list<Order>  $alreadyShipping  Orders passed over because a parcel is already out for them.
     * @param  list<Order>  $needingAnArea  Orders passed over because the Courier names its own areas and none matched.
     * @param  list<Order>  $deleted  Orders passed over because they were gone by the time the batch reached them.
     */
    public function __construct(
        public readonly array $written,
        public readonly array $alreadyShipping,
        public readonly array $needingAnArea,
        public readonly array $deleted = [],
    ) {}

    public function count(): int
    {
        return count($this->written);
    }
}
