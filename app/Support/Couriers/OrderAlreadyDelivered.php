<?php

namespace App\Support\Couriers;

use App\Models\Order;
use RuntimeException;

/**
 * A parcel of this Order's already got to the door, so another one is not
 * sent.
 *
 * The other half of "an Order that may be sent" (ADR-0018). Active answers
 * only whether something is out *now*, and every delivered Order satisfies
 * it — so without this, the second press of a button on a week-old Order
 * would book a duplicate parcel the Merchant pays for and the Customer did
 * not order. A parcel that came back is emphatically not this: a return is a
 * question a User answers by sending again, and an arrival is finished.
 *
 * Refused under the Order's lock against a fresh read rather than by the
 * button being hidden, exactly as `OrderAlreadyShipping` is, and not a
 * `BookingNotMade`: no Courier was asked and none said anything.
 */
class OrderAlreadyDelivered extends RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct("Order {$order->order_number} has already been delivered, so it cannot be sent again.");
    }
}
