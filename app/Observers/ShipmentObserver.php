<?php

namespace App\Observers;

use App\Models\Shipment;
use App\Support\Inventory\StockLedger;

/**
 * What a parcel's progress owes the stock ledger.
 *
 * An observer rather than a call at each place a Shipment is written, and
 * deliberately so (ADR-0021). A status is written from four places in the
 * courier layer today — `CourierBooker` twice, `CourierDispatcher` dispatching
 * a batch row and `CourierPoller` applying what a Courier said — and
 * `Shipment::abandon()` makes five. Five call sites is five places the next
 * Courier path can forget; this is one, and it cannot be gone round.
 *
 * It decides nothing. Both halves ask `StockLedger` to reconcile and the
 * ledger answers what that parcel leaves owing, because the ledger is the
 * only thing that may write a movement and the only thing that knows what a
 * unit was worth when it moved.
 */
class ShipmentObserver
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * A single booking writes the row already carrying its Consignment: the
     * attempt is made unsaved and saved only once a Courier has answered
     * (ADR-0020), so the parcel has left the building by the time this row
     * first exists. A batch's row is written Awaiting Dispatch and leaves
     * nothing owing until the sweep hands it over.
     */
    public function created(Shipment $shipment): void
    {
        $this->ledger->reconcile($shipment);
    }

    /**
     * Status and abandonment, and the Consignment beside them. Abandoned is a
     * time and a name rather than a status (ADR-0018), so a Loss would be
     * invisible to anything watching the status column alone — and Refused at
     * Booking, the other time-and-a-name, needs nothing watched because a
     * parcel that never left moves nothing.
     *
     * The Consignment is watched because it is what `wasHandedOver()` reads:
     * both writers set it in the same save as the status today, so it changes
     * nothing now, and a later Courier path that writes one without the other
     * is exactly the forgetting this observer exists to make impossible.
     *
     * These columns rather than every save because a sweep stamps `polled_at`
     * on every parcel it asks about, and a ledger asked to reconcile a hundred
     * parcels that have not moved is a hundred questions about nothing.
     */
    public function updated(Shipment $shipment): void
    {
        if ($shipment->wasChanged(['status', 'consignment_reference', 'abandoned_at'])) {
            $this->ledger->reconcile($shipment);
        }
    }
}
