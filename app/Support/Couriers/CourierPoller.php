<?php

namespace App\Support\Couriers;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Support\Facades\Log;

/**
 * One outbound question to a Courier about one parcel, and the writing down
 * of what it answered.
 *
 * The only thing in the system that may move a Shipment's canonical status
 * from a Courier's word, and deliberately so (ADR-0019). Two things ask it:
 * the sweep, walking every parcel the couriers are carrying, and a webhook
 * claiming something changed — which is read only far enough to find the
 * Shipment and then comes here, so that what the timeline holds is always
 * what our own call learned and never what a guessable address was told.
 *
 * It answers rather than throws, because `CourierDriver::track()` does: a
 * sweep must walk on from a parcel it could not ask about, and a webhook must
 * be heard whatever became of the call it triggered.
 */
class CourierPoller
{
    /**
     * Ask this parcel's Courier where it stands, and write down what it said.
     *
     * Silence writes nothing at all — not even `polled_at` — which is exactly
     * what makes the Shipment due again on the next sweep; do not tidy that
     * by stamping the column.
     *
     * Every state the Courier reported becomes a Shipment Event, and the
     * database is what makes a history re-imported on every poll one timeline
     * rather than one timeline per poll (ADR-0019) — so this loops and never
     * asks first. Where the Courier reported anything, the last of it is
     * where the parcel stands now and the Shipment's own columns follow it.
     *
     * A Courier that answered and would not account for the parcel is written
     * down as Unknown in the Courier's own words, which is what Unknown is
     * for: somebody has to look at this. It is not terminal, so the parcel
     * goes on being asked about and is flagged stuck in its own time — and
     * the same refusal repeated every half hour is one timeline entry, for
     * the same reason a repeated status is.
     *
     * The driver is resolved off the parcel rather than handed in, so that a
     * webhook about one parcel and a sweep over a hundred call this the same
     * way. It costs a driver per parcel on a sweep and nothing more: the
     * Settings group is a singleton, and what a driver holds between calls —
     * Pathao's access token, RedEx's area list — is in the cache and not on
     * the instance.
     */
    public function ask(Shipment $shipment): ShipmentPolled
    {
        $progress = $shipment->courier->driver()->track($shipment);

        if (! $progress->isFinal) {
            return new ShipmentPolled($progress, 0);
        }

        if (! $progress->answered) {
            Log::warning('A courier would not account for a parcel it was asked about.', [
                'courier' => $shipment->courier->value,
                'invoice' => $shipment->invoice_reference,
                'consignment' => $shipment->consignment_reference,
                'error' => $progress->error,
            ]);
        }

        $reported = $progress->answered
            ? $progress->reported
            : [new ReportedStatus(ShipmentStatus::Unknown, ShipmentEvent::asAWord((string) $progress->error))];

        $recorded = 0;

        foreach ($reported as $state) {
            if ($shipment->recordEvent($state->status, $state->rawStatus, $state->occurredAt)->wasRecentlyCreated) {
                $recorded++;
            }
        }

        $latest = $reported === [] ? null : $reported[array_key_last($reported)];

        $shipment->fill(array_merge(
            ['polled_at' => now()],
            $latest === null ? [] : ['status' => $latest->status, 'raw_status' => $latest->rawStatus],
        ))->save();

        return new ShipmentPolled($progress, $recorded);
    }
}
