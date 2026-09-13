<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * A morning's Orders handed to one Courier in one action: every parcel
 * written down, and not one of them dispatched here.
 *
 * The batch half of `CourierBooker` (ADR-0020). A single booking calls the
 * Courier inside the request because an operator is waiting on the
 * Consignment with the Customer on the phone; nobody is watching a batch of
 * two hundred, no web request could survive making two hundred calls, and
 * RedEx has no bulk endpoint at all. So this writes the rows and
 * `couriers:sweep` hands them over.
 *
 * Writing first is not an implementation detail, it is the whole point: a
 * connection dropped after a bulk endpoint created five hundred consignments
 * but before the answer was read leaves five hundred real parcels with no
 * local record, and a second press creates five hundred more that the
 * Merchant pays for. A batch that stops halfway here leaves a row for every
 * parcel it wrote, and each row is offered exactly once because it is offered
 * from the row.
 *
 * Every Order is written under its own lock and on its own account, so one
 * Order that cannot be sent costs that Order and not the batch. Nothing here
 * asks a Courier anything, which is what makes two hundred Orders two hundred
 * cheap writes rather than a request nobody could keep open.
 */
class BatchBooker
{
    public function __construct(private readonly CourierBooker $booker) {}

    /**
     * Write an attempt down for each of these Orders, and answer with what
     * was written and what was passed over.
     *
     * The delivery area is the one thing worked out here rather than taken
     * from a User: a Courier that will not read an address in free text is
     * answered from the Order's own address and District, out of the list that
     * Courier publishes — fetched once for the whole batch and not once per
     * parcel. A guess that cannot be made is an Order reported back as needing
     * an area, never a parcel sent to whichever depot a blank resolves to.
     *
     * A Courier this installation cannot speak to stops the whole batch
     * before anything is written, and is raised rather than reported: it is
     * one fact about the installation and not a list of two hundred Orders'
     * problems.
     *
     * An Order a parcel already reached a door for is passed over like any
     * other Order that cannot be sent — a User who ticked a week of the queue
     * has ticked delivered Orders, and a batch that booked them would be a
     * duplicate parcel each, paid for and unordered.
     *
     * @param  iterable<Order>  $orders
     *
     * @throws CourierNotUsable when this installation cannot speak to the Courier at all.
     */
    public function write(iterable $orders, Courier $courier): BatchWritten
    {
        $driver = $courier->driver();

        /** Asked once for the batch: the driver holds its list between requests, so two hundred parcels cost one fetch. */
        $areas = $driver->needsAreaResolution() ? $driver->deliveryAreas() : [];

        $written = [];
        $alreadyShipping = [];
        $alreadyDelivered = [];
        $needingAnArea = [];
        $deleted = [];

        foreach ($orders as $order) {
            try {
                $written[] = $this->booker->write($order, $courier, $this->detailsFor($order, $driver, $areas));
            } catch (OrderAlreadyShipping) {
                $alreadyShipping[] = $order;
            } catch (OrderAlreadyDelivered) {
                /** Finished, and the only skip here that will still be true tomorrow: a second parcel is one nobody ordered. */
                $alreadyDelivered[] = $order;
            } catch (DeliveryAreaNotResolved) {
                $needingAnArea[] = $order;
            } catch (ModelNotFoundException) {
                /**
                 * Gone by the time the batch reached it. The Orders screens
                 * drop the soft-delete scope so a deleted Order is still
                 * listed and still tickable, and the write reads it back
                 * through the scope that is still on the model — so this is
                 * an Order that should never have existed, not an error, and
                 * it is reported rather than allowed to unwind the batch.
                 */
                $deleted[] = $order;
            }
        }

        $batch = new BatchWritten($written, $alreadyShipping, $needingAnArea, $deleted, $alreadyDelivered);

        Log::info('A batch of parcels was written down to be dispatched.', [
            'courier' => $courier->value,
            'written' => $batch->count(),
            'already_shipping' => count($alreadyShipping),
            'already_delivered' => count($alreadyDelivered),
            'needing_an_area' => count($needingAnArea),
            'deleted' => count($deleted),
        ]);

        return $batch;
    }

    /**
     * What this parcel is to be sent with: the Merchant's standing defaults
     * for everything the Courier asks, and the system's own guess at the one
     * thing nobody is here to answer.
     *
     * Nobody was asked, so the Order goes exactly as it stands and the note
     * the Customer left on it travels with the parcel — a batch has no dialog
     * anybody could have cleared.
     *
     * @param  list<DeliveryArea>  $areas
     */
    private function detailsFor(Order $order, CourierDriver $driver, array $areas): ParcelDetails
    {
        if (! $driver->needsAreaResolution()) {
            return ParcelDetails::none();
        }

        $guess = DeliveryAreaMatcher::guess($order->customer_address, $order->customer_district, $areas);

        return ParcelDetails::guessed([DeliveryArea::ANSWER => $guess?->id]);
    }
}
