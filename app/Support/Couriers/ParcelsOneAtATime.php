<?php

namespace App\Support\Couriers;

use App\Models\Shipment;

/**
 * A batch handed to a Courier that takes one parcel per call, offered
 * parcel by parcel until it is done or the Courier stops answering.
 *
 * Shared between the drivers that have no usable bulk endpoint rather than
 * copied into each, for the reason `ParcelField::pickupStore()` is shared: two
 * copies of one loop drift, and the difference between them would be which
 * Courier writes down the parcels it took before the line dropped.
 *
 * A refusal is this parcel's own and stops nothing — the rest of the batch is
 * still offered, because a Courier objecting to one address says nothing
 * about the next. Anything else is the Courier no longer answering, so the
 * batch stops where it stands and what it settled first is carried back: those
 * are real parcels, and a sweep that threw them away would book them twice.
 */
final class ParcelsOneAtATime
{
    /**
     * @param  list<Shipment>  $shipments
     */
    public static function handedTo(CourierDriver $driver, array $shipments): BatchBooked
    {
        $accepted = [];
        $refused = [];

        foreach ($shipments as $shipment) {
            try {
                $accepted[$shipment->invoice_reference] = $driver->book($shipment);
            } catch (CourierRefusedBooking $refusal) {
                $refused[$shipment->invoice_reference] = $refusal->getMessage();
            } catch (BookingNotMade $silence) {
                return BatchBooked::silencedAfter($silence->getMessage(), $accepted, $refused);
            }
        }

        return BatchBooked::of($accepted, $refused);
    }
}
