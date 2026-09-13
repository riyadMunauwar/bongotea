<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hand one Courier the parcels a bulk booking wrote down for it, in the
 * largest batches that Courier will take.
 *
 * The other half of the outbox (ADR-0020): `BatchBooker` writes the rows
 * while a User watches, and this offers them with nobody watching at all.
 * Every parcel is offered from its own row and settled onto that row, so a
 * run cut off halfway leaves the parcels it placed recorded as placed and the
 * rest still waiting — which is the whole reason the rows are written first.
 *
 * How many parcels one call carries is the driver's own property and never a
 * constant here, exactly as `TrackingSweep` asks a Provider: one Courier takes
 * five hundred in a single request, two take one each, and the loop is the
 * same either way because a Courier without a bulk endpoint declares a batch
 * limit of 1 rather than declaring "no bulk".
 *
 * A Courier that goes quiet stops its own run where it stands and takes
 * nothing else with it, as an unreachable Provider stops a Destination's:
 * every later batch would fail the same way and cost a call to find out. The
 * parcels it never answered about stay written and are offered again on the
 * next sweep.
 *
 * Nothing here reads or writes an Order (ADR-0018). A parcel placed does not
 * confirm an Order and a parcel refused does not cancel one.
 */
class CourierDispatcher
{
    /**
     * Offer every parcel written down for this Courier, and answer with how
     * many it took, how many it refused, and why it stopped answering where
     * it did.
     *
     * @return array{placed: int, refused: int, error: string|null}
     */
    public function dispatch(Courier $courier): array
    {
        $driver = $courier->driver();
        $tally = ['placed' => 0, 'refused' => 0, 'error' => null];

        Shipment::query()
            ->awaitingDispatch()
            ->where('courier', $courier)
            ->chunkById($driver->batchLimit(), function (Collection $batch) use ($courier, $driver, &$tally): bool {
                /** @var list<Shipment> $parcels */
                $parcels = $batch->values()->all();

                $answer = $driver->bookAll($parcels);

                foreach ($parcels as $parcel) {
                    $booked = $answer->accepted[$parcel->invoice_reference] ?? null;
                    $reason = $answer->refused[$parcel->invoice_reference] ?? null;

                    if ($booked !== null) {
                        $this->place($parcel, $booked);
                        $tally['placed']++;

                        continue;
                    }

                    /** Named neither way: nothing is known about it, so the row stays as it was and the next sweep offers it again. */
                    if ($reason !== null) {
                        $this->refuse($parcel, $reason);
                        $tally['refused']++;
                    }
                }

                if ($answer->answeredThroughout()) {
                    return true;
                }

                $tally['error'] = $answer->error;

                Log::warning('A courier stopped answering partway through a batch, and the rest stay written.', [
                    'courier' => $courier->value,
                    'parcels' => count($parcels),
                    'placed' => count($answer->accepted),
                    'refused' => count($answer->refused),
                    'error' => $answer->error,
                ]);

                return false;
            });

        return $tally;
    }

    /**
     * Write down that the Courier took this parcel: its Consignment, the code
     * the Customer follows, and the moment the attempt began.
     *
     * The row stops being one to dispatch and becomes one to ask about, which
     * is the same row and the same invoice reference it was written with —
     * so a run that dies between the call and this write offers the parcel
     * again under a reference the Courier has already seen and will refuse as
     * a duplicate, rather than paying for a second parcel.
     */
    private function place(Shipment $shipment, ShipmentBooked $booked): void
    {
        $shipment->fill([
            'consignment_reference' => $booked->consignmentReference,
            'tracking_code' => $booked->trackingCode,
            'status' => $booked->status,
            'raw_status' => $booked->rawStatus,
            'courier_charge' => $booked->charge,
            'booked_at' => now(),
            'booking_response' => $booked->response,
        ])->save();

        /** The first thing that happened to this parcel, in the Courier's own word for it where it gave one. */
        $shipment->recordEvent($booked->status, $booked->rawStatus);
    }

    /**
     * Write down that the Courier would not take this parcel, in the words it
     * refused it in.
     *
     * A time and a reason and no change of status, shaped like Abandoned: the
     * row goes on saying it was never dispatched, and the refusal is what
     * takes it out of Active so the Order is one to send again. Nothing is
     * retried — a Courier objecting to an address will object to the same
     * address in five minutes, and what it said is on the row for a User to
     * act on.
     */
    private function refuse(Shipment $shipment, string $reason): void
    {
        $shipment->fill([
            'refused_at' => now(),
            'refusal_reason' => $reason,
        ])->save();

        Log::warning('A courier refused a parcel it was offered from a batch.', [
            'courier' => $shipment->courier->value,
            'invoice' => $shipment->invoice_reference,
            'reason' => $reason,
        ]);
    }
}
