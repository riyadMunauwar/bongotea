<?php

namespace App\Support\Couriers;

use App\Models\Order;
use RuntimeException;

/**
 * A parcel is already out for this Order, so a second one is not sent.
 *
 * The invariant that stops a Merchant paying for two parcels, refused under
 * the Order's lock against a fresh read rather than by the button being
 * hidden: the screen a User pressed may be minutes old, and a colleague may
 * have booked the same Order in between (ADR-0018).
 *
 * Not a `BookingNotMade`: no Courier was asked and none said anything, so
 * there is nothing of a Courier's to word back.
 */
class OrderAlreadyShipping extends RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct("Order {$order->order_number} already has a parcel out with a courier.");
    }
}
