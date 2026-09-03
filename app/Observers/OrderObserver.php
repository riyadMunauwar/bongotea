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
     *
     * The subtotal is re-read from the lines unless this save is the one
     * carrying a new one. An Order's lines are saved through their own model
     * events — Filament's repeater saves the relationship before it saves the
     * record — and those run against an Order instance loaded through
     * `OrderLine::order()`, not the instance being saved here. Trusting the
     * in-memory figure means a quantity and a Delivery Charge corrected in the
     * same save write a total derived from the pre-edit subtotal: the row ends
     * up internally inconsistent, and the door is where you find out.
     */
    public function saving(Order $order): void
    {
        if ($order->exists && ! $order->isDirty('subtotal')) {
            $order->subtotal = (int) $order->lines()->sum('line_total');
        }

        $order->total = (int) $order->subtotal + (int) $order->delivery_charge;
    }
}
