<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Settings\CourierSettings;
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
 *
 * One thing does outlive a refusal, and only one: what a User corrected about
 * the Order in the send dialog (`correct()`). That is their own work and not
 * an attempt, and it is most often the very thing the Courier objected to.
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
    public const PLACEHOLDER_NAME = 'Customer';

    public const PLACEHOLDER_ADDRESS = 'Not provided';

    public function __construct(private readonly CourierSettings $settings) {}

    /**
     * Book this Order with this Courier and answer with the Shipment that
     * records it.
     *
     * The booking happens under the Order's lock, including the call
     * itself: "an Order has at most one Active Shipment" is the invariant
     * that stops a Merchant paying for two parcels, and a lock released
     * before the Courier answers does not hold it. Only this Order's row is
     * held, and only for as long as one Courier takes to answer.
     *
     * The details are what a User confirmed in the send dialog, or `none()`
     * where nobody was asked. Their corrections are saved first and on their
     * own (`correct()`), so the Order carries the address we now know is right
     * and the attempt, reading it back under the lock, snapshots exactly what
     * went out with it.
     *
     * @throws OrderAlreadyShipping when a parcel is already out for this Order.
     * @throws OrderAlreadyDelivered when a parcel of this Order's already reached the door.
     * @throws CourierNotUsable when this installation cannot speak to the Courier at all.
     * @throws DeliveryAreaNotResolved when the Courier names its own areas and none was chosen.
     * @throws CourierRefusedBooking when the Courier answered and said no.
     * @throws CourierUnreachable when the Courier could not be asked.
     */
    public function book(Order $order, Courier $courier, ?ParcelDetails $details = null): Shipment
    {
        $details ??= ParcelDetails::none();

        $this->correct($order, $details);

        return DB::transaction(function () use ($order, $courier, $details): Shipment {
            $locked = $this->readyToSend($order, $courier);

            $shipment = $this->attempt($locked, $courier, $details);

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
     * Write an attempt down to be dispatched later, without asking the
     * Courier anything at all.
     *
     * How a batch is booked (ADR-0020): nobody is watching two hundred
     * parcels and no web request could survive making two hundred calls, so
     * every row is written first and `couriers:sweep` hands them over. The row
     * is the record that makes a batch interrupted halfway recoverable — the
     * parcels it already dispatched are on it, and the ones it did not are
     * still waiting rather than lost.
     *
     * Everything the booking would have refused, this refuses now, under the
     * same lock and in the same words: an Order already shipping, an Order
     * already delivered, a Courier this installation cannot speak to, and a
     * parcel whose delivery area the Courier insists on and nobody could
     * name. Nothing is written for any of them, so a batch skips that Order
     * and says why rather than committing a parcel it knows will not go.
     *
     * @throws OrderAlreadyShipping when a parcel is already out for this Order.
     * @throws OrderAlreadyDelivered when a parcel of this Order's already reached the door.
     * @throws CourierNotUsable when this installation cannot speak to the Courier at all.
     * @throws DeliveryAreaNotResolved when the Courier names its own areas and none was resolved.
     */
    public function write(Order $order, Courier $courier, ?ParcelDetails $details = null): Shipment
    {
        $details ??= ParcelDetails::none();

        return DB::transaction(function () use ($order, $courier, $details): Shipment {
            $shipment = $this->attempt($this->readyToSend($order, $courier), $courier, $details);

            /** The Courier has not seen it, so it holds no Consignment, no word of its own and no `booked_at`. */
            $shipment->status = ShipmentStatus::Written;
            $shipment->save();

            return $shipment;
        });
    }

    /**
     * This Order, read fresh under its own lock, with all three of the things
     * that stop a parcel going asked again.
     *
     * Asked here and not only by the screen: the screen a User pressed the
     * button on may be minutes old, a Merchant may have switched a Courier off
     * since it was drawn, and a batch may have written a row for this Order
     * between the list being counted and this Order being reached.
     *
     * The two readings of "this Order is not one to send" are deliberately
     * separate, because only one of them is temporary: a parcel out now is an
     * Order to send later, where a parcel that arrived is an Order that is
     * finished. A parcel that came back is neither — it is Active no longer
     * and it never reached a door, which is exactly what makes a re-send the
     * answer to it (ADR-0018).
     *
     * @throws OrderAlreadyShipping
     * @throws OrderAlreadyDelivered
     * @throws CourierNotUsable
     */
    private function readyToSend(Order $order, Courier $courier): Order
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

        if ($locked->isShipping()) {
            throw new OrderAlreadyShipping($locked);
        }

        if ($locked->hasArrived()) {
            throw new OrderAlreadyDelivered($locked);
        }

        if (($refusal = $courier->refusal()) !== null) {
            throw new CourierNotUsable($refusal);
        }

        return $locked;
    }

    /**
     * Save what the User corrected onto the Order, before a Courier is asked
     * anything and in a transaction of its own.
     *
     * Deliberately outside the booking's, which is the one place this class
     * keeps something short-lived: a Courier that refuses a parcel usually
     * refuses it *because* the address was wrong, and a correction rolled back
     * with the refusal is the User's own work thrown away at exactly the moment
     * they are rescuing the Order. What they typed is true whatever the Courier
     * then says about it, so it is committed on its own account.
     *
     * The invariant is untouched: the booking re-reads the Order under its own
     * lock and still refuses a second parcel there.
     */
    private function correct(Order $order, ParcelDetails $details): void
    {
        $corrections = $details->corrections();

        if ($corrections === []) {
            return;
        }

        DB::transaction(function () use ($order, $corrections): void {
            Order::query()->lockForUpdate()->findOrFail($order->getKey())->fill($corrections)->save();
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
     *
     * The recipient is read off the Order, which the corrections have already
     * been written onto. The note is the one detail read off the dialog
     * instead: it is a Customer's own answer to a Merchant's question, and an
     * instruction a User adds for this rider does not get to rewrite what the
     * Customer wrote on the Page.
     */
    private function attempt(Order $order, Courier $courier, ParcelDetails $details): Shipment
    {
        return $order->shipments()->make([
            'courier' => $courier,
            'invoice_reference' => Shipment::nextInvoiceReference($order),
            'collect_amount' => $order->amountDueOnDelivery(),
            'recipient_name' => $order->customer_name ?: self::PLACEHOLDER_NAME,
            'recipient_phone' => $order->customer_phone,
            'recipient_address' => $order->customer_address ?: self::PLACEHOLDER_ADDRESS,
            'recipient_district' => $order->customer_district,
            'recipient_note' => $details->noteOr($order->deliveryNote()),
            'parcel_details' => $this->parcelDetails($order, $courier, $details),
        ]);
    }

    /**
     * Everything this Courier asks about the parcel itself, resolved once and
     * written onto the attempt: what the User overrode where they overrode it,
     * the Merchant's installation-wide default everywhere else.
     *
     * Resolved here rather than in the driver so that every Courier's defaults
     * are applied the same way, and so a Courier asking a question nobody was
     * shown — a choice with one answer — still sends it.
     *
     * The delivery area leads, where the Courier insists on one, because it is
     * the thing that decides where the parcel goes rather than what it is.
     *
     * @return array<string, string>
     *
     * @throws DeliveryAreaNotResolved when the Courier names areas and this parcel names none.
     */
    private function parcelDetails(Order $order, Courier $courier, ParcelDetails $details): array
    {
        $driver = $courier->driver();
        $resolved = $driver->needsAreaResolution() ? $this->deliveryArea($driver, $details)->asParcelDetails() : [];

        foreach ($driver->parcelFields() as $field) {
            $resolved[$field->name] = $field->valueFor($details->answerTo($field->name), $this->settings, $order);
        }

        return $resolved;
    }

    /**
     * The area this parcel was confirmed to be going to, out of the Courier's
     * own list.
     *
     * Asked of the driver and never of a Courier by name, and refused here
     * rather than by the Courier — `DeliveryAreaNotResolved` says why. An id
     * the Courier's list no longer holds is refused the same way as none at
     * all: a Courier that has dropped an area since the dialog opened has not
     * agreed to deliver there.
     *
     * @throws DeliveryAreaNotResolved
     */
    private function deliveryArea(CourierDriver $driver, ParcelDetails $details): DeliveryArea
    {
        $chosen = $details->deliveryArea();

        /** Nothing chosen is answered without asking the Courier for its list: there is nothing to look an answer up in. */
        $area = $chosen === null ? null : DeliveryArea::find($chosen, $driver->deliveryAreas());

        if ($area === null) {
            throw new DeliveryAreaNotResolved($driver->courier());
        }

        return $area;
    }
}
