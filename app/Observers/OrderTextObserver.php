<?php

namespace App\Observers;

use App\Enums\OrderText;
use App\Models\Order;
use App\Support\Sms\OrderTexts;

/**
 * What an Order's own changes owe by text message: the placed text and the
 * Staff Alert when it is captured, and a text when its status moves.
 *
 * An observer rather than a call at each place an Order is captured or
 * moved along, for the reason `ShipmentObserver` is one: the storefront
 * Checkout, an operator's phone order and the edit screen all write Orders,
 * and one place cannot be forgotten by the next.
 */
class OrderTextObserver
{
    public function __construct(private readonly OrderTexts $texts) {}

    public function created(Order $order): void
    {
        $this->texts->owe($order, OrderText::Placed);
        $this->texts->alertStaffToNewOrder($order);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $text = OrderText::forStatus($order->status);

        if ($text !== null) {
            $this->texts->owe($order, $text);
        }
    }
}
