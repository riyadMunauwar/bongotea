<?php

namespace App\Observers;

use App\Models\Shipment;
use App\Support\Sms\OrderTexts;

/**
 * The text an Order is owed when a Courier takes its parcel.
 *
 * "Sent" is the moment a Consignment comes back, which is `wasHandedOver()`:
 * a single booking writes the row already carrying one, and a batch row
 * gains one when the sweep dispatches it. A row written Awaiting Dispatch,
 * or refused, has not been sent anywhere and texts nobody.
 */
class ShipmentTextObserver
{
    public function __construct(private readonly OrderTexts $texts) {}

    public function created(Shipment $shipment): void
    {
        if ($shipment->wasHandedOver()) {
            $this->texts->oweSent($shipment);
        }
    }

    public function updated(Shipment $shipment): void
    {
        if ($shipment->wasChanged('consignment_reference')
            && $shipment->getOriginal('consignment_reference') === null
            && $shipment->wasHandedOver()) {
            $this->texts->oweSent($shipment);
        }
    }
}
