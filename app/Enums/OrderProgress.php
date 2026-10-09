<?php

namespace App\Enums;

use App\Models\Order;
use App\Models\Shipment;

/**
 * Where an Order stands as its Customer sees it after Signing in: four steps,
 * or Cancelled drawn instead of them.
 *
 * Worked out from the Order and its Shipments and never kept or set by
 * anybody, so it cannot disagree with them. A parcel that came back, was
 * Abandoned or was refused reads as Confirmed again: to the Customer the
 * Order is waiting to be sent, and whether it will be is a question a User
 * has not answered yet. Nothing here names a Courier or its Consignment.
 */
enum OrderProgress: string
{
    case Received = 'received';
    case Confirmed = 'confirmed';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Read off the Order's Shipments as loaded, so a list of Orders eager-
     * loads them once rather than asking per Order.
     */
    public static function of(Order $order): self
    {
        if ($order->status === OrderStatus::Cancelled) {
            return self::Cancelled;
        }

        $shipments = $order->shipments;

        if ($shipments->contains(fn (Shipment $shipment): bool => $shipment->status->isArrival())) {
            return self::Delivered;
        }

        if ($shipments->contains(fn (Shipment $shipment): bool => $shipment->isActive())) {
            return self::Sent;
        }

        return $order->status === OrderStatus::New ? self::Received : self::Confirmed;
    }
}
