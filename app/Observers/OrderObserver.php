<?php

namespace App\Observers;

use App\Models\Order;

class OrderObserver
{
    /**
     * Every Order gets its public handle here rather than at the one call site
     * that captures one, so a factory, a seeder or an admin-created Order can
     * never produce a row the confirmation page has no way to address.
     */
    public function creating(Order $order): void
    {
        if (blank($order->order_number)) {
            $order->order_number = Order::generateNumber();
        }
    }

    /**
     * The total is what the courier collects at the door, so it always includes
     * delivery.
     *
     * Maintained as an invariant on every save rather than computed at Capture:
     * the Delivery Charge is editable afterwards, and a total that silently
     * stopped matching its parts would be discovered by a fulfiller doing
     * mental arithmetic against a cash payment.
     */
    public function saving(Order $order): void
    {
        $order->total = (int) $order->subtotal + (int) $order->delivery_charge;
    }
}
