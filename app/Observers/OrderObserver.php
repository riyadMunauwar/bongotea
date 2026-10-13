<?php

namespace App\Observers;

use App\Models\Order;
use LogicException;

class OrderObserver
{
    /**
     * Every Order gets its public handle here rather than at the one call site
     * that captures one, so a factory, a seeder or an admin-created Order can
     * never produce a row the Order Complete Page has no way to address.
     */
    public function creating(Order $order): void
    {
        if (blank($order->order_number)) {
            $order->order_number = Order::generateNumber();
        }
    }

    /**
     * An Order is bulk or retail for life (ADR-0034): the two are priced by
     * rules that cannot share an Order, so no edit, however it arrives, may
     * turn one into the other.
     */
    public function updating(Order $order): void
    {
        if ($order->isDirty('kind')) {
            throw new LogicException("Order {$order->order_number} cannot change its kind after Capture.");
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

        /** The weight is the lines' too, for the same reason. */
        if ($order->exists && ! $order->isDirty('total_kilograms')) {
            $order->total_kilograms = $order->bulkKilograms();
        }

        $order->total = (int) $order->subtotal + (int) $order->delivery_charge;
    }
}
