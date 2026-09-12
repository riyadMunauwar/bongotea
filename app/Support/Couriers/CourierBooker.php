<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hand one Order's parcel to one Courier and write down the attempt.
 *
 * The call is made inside the request, the way `PaymentStarter` calls a
 * gateway: a User is waiting on the Consignment and the tracking code, often
 * with the Customer still on the phone, and "queued" is not an answer to what
 * they asked (ADR-0020). A batch is the other shape and is swept; nothing
 * here loops.
 *
 * Nothing is recorded for an attempt that did not happen. Unlike a Payment —
 * which is written Pending before the gateway is asked, because a Customer
 * may be at the gateway's page paying while our record says nothing — a
 * refused booking leaves no parcel anywhere, and a Shipment row for it would
 * be an attempt the Merchant is shown, filtered and reported on that never
 * cost them anything. The row is written only once a Courier has answered
 * with a Consignment.
 */
class CourierBooker
{
    /**
     * What stands in for a detail the Checkout never asked for.
     *
     * Every Courier calls the recipient's name and address mandatory, and a
     * Merchant may run a Page that asks for neither — the same problem the
     * gateways have, answered the same way (`SslCommerz::PLACEHOLDER_*`): a
     * fixed word goes out rather than the Checkout growing a question for a
     * Courier's sake. It is recorded on the Shipment as sent, so what a User
     * corrects on the next attempt is visibly different from what went out on
     * this one.
     */
    private const PLACEHOLDER_NAME = 'Customer';

    private const PLACEHOLDER_ADDRESS = 'Not provided';

    /**
     * Book this Order with this Courier and answer with the Shipment that
     * records it.
     *
     * The whole of it happens under the Order's lock, including the call
     * itself: "an Order has at most one Active Shipment" is the invariant
     * that stops a Merchant paying for two parcels, and a lock released
     * before the Courier answers does not hold it. Only this Order's row is
     * held, and only for as long as one Courier takes to answer.
     *
     * @throws OrderAlreadyShipping when a parcel is already out for this Order.
     * @throws CourierNotUsable when this installation cannot speak to the Courier at all.
     * @throws CourierRefusedBooking when the Courier answered and said no.
     * @throws CourierUnreachable when the Courier could not be asked.
     */
    public function book(Order $order, Courier $courier): Shipment
    {
        return DB::transaction(function () use ($order, $courier): Shipment {
            /** Read fresh under the lock: the screen the User pressed the button on may be minutes old. */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->isShipping()) {
                throw new OrderAlreadyShipping($locked);
            }

            /** Asked here and not only by the screen: a Merchant may have switched a Courier off since the button was drawn. */
            if (($refusal = $courier->refusal()) !== null) {
                throw new CourierNotUsable($refusal);
            }

            $shipment = $this->attempt($locked, $courier);

            try {
                $booked = $courier->driver()->book($shipment);
            } catch (BookingNotMade $exception) {
                Log::warning($exception->getMessage(), [
                    'order' => $locked->order_number,
                    'courier' => $courier->value,
                    'invoice' => $shipment->invoice_reference,
                ]);

                throw $exception;
            }

            $shipment->fill([
                'consignment_reference' => $booked->consignmentReference,
                'tracking_code' => $booked->trackingCode,
                'status' => $booked->status,
                'raw_status' => $booked->rawStatus,
                'courier_charge' => $booked->charge,
                'booked_at' => now(),
                'booking_response' => $booked->response,
            ])->save();

            /** The first thing that happened to this parcel, in the Courier's own word for it. */
            $shipment->recordEvent($booked->status, $booked->rawStatus);

            return $shipment->refresh();
        });
    }

    /**
     * The attempt as it will be sent, not yet saved: its own invoice
     * reference, what the Courier is to collect, and the recipient as this
     * attempt is being told it.
     *
     * Unsaved on purpose — a Courier that refuses must leave nothing behind —
     * and the driver takes it rather than the Order so that what was sent and
     * what was recorded cannot drift apart.
     */
    private function attempt(Order $order, Courier $courier): Shipment
    {
        return $order->shipments()->make([
            'courier' => $courier,
            'invoice_reference' => Shipment::nextInvoiceReference($order),
            'collect_amount' => $order->amountDueOnDelivery(),
            'recipient_name' => $order->customer_name ?: self::PLACEHOLDER_NAME,
            'recipient_phone' => $order->customer_phone,
            'recipient_address' => $order->customer_address ?: self::PLACEHOLDER_ADDRESS,
            'recipient_district' => $order->customer_district,
            'recipient_note' => $order->deliveryNote(),
        ]);
    }
}
