<?php

namespace App\Http\Controllers;

use App\Enums\Courier;
use App\Models\Shipment;
use App\Support\Couriers\Announcement;
use App\Support\Couriers\CourierPoller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseCode;
use Throwable;

/**
 * Where a Courier announces on its own that something happened to a parcel.
 *
 * One address per Courier, addressed by our own invoice reference in the body
 * and by nothing the Courier put in its parameters, and outside
 * request-forgery protection because a Courier's server posts with no
 * session. It believes nothing it is told about state (ADR-0019): an
 * announcement claiming a status is read only far enough to find our
 * Shipment, and the system then puts the question to the Courier itself, as a
 * Payment Notification triggers a Verification and records nothing by itself.
 *
 * The one exception is narration — a line of prose with no state behind it,
 * which the Courier that sends it keeps no history to be asked about. That is
 * written to the timeline as text and moves nothing: not a canonical status,
 * not a filter, not a figure, not a Shipment's Active-ness.
 *
 * Nothing here names a Courier or reads a Courier's vocabulary. Which fields
 * an announcement is written in is its driver's business, and a Courier that
 * announces nothing reads nothing off it and is heard all the same.
 */
class CourierController extends Controller
{
    public function __construct(private readonly CourierPoller $poller) {}

    /**
     * An announcement: a Courier telling the system, on its own, that
     * something happened to a parcel.
     *
     * Answers 200 whatever happened, so the Courier stops retrying — one
     * naming no Shipment we hold is garbage from a guessable address and is
     * logged and ignored rather than asked about, and one whose own outbound
     * call fell over is reported rather than returned, because a retry from
     * the Courier would fail the same way and the sweep asks again regardless.
     */
    public function notify(Request $request, string $courier): Response
    {
        $announcing = Courier::from($courier);

        $announcement = $announcing->driver()->readAnnouncement($request);

        if ($announcement === null) {
            return $this->heard();
        }

        $shipment = Shipment::query()->where('invoice_reference', $announcement->invoiceReference)->first();

        if ($shipment === null || $shipment->courier !== $announcing) {
            return $this->ignored("A {$courier} announcement named [{$announcement->invoiceReference}], which is no parcel under it.", $announcing, $announcement);
        }

        if ($announcement->claimsAStatusChange()) {
            /**
             * The claim has done its whole job by naming the parcel. What it
             * said the parcel's state is goes no further than this line:
             * where the parcel stands is whatever our own call to the Courier
             * answers, so a forged word moves nothing because nothing reads
             * it.
             */
            try {
                $this->poller->ask($shipment);
            } catch (Throwable $failure) {
                report($failure);
            }

            return $this->heard();
        }

        if ($announcement->narrates()) {
            $shipment->recordNarration((string) $announcement->narration, $announcement->occurredAt);
        }

        return $this->heard();
    }

    /**
     * What every announcement is answered with: 200 and nothing else, so the
     * Courier stops retrying. What became of the parcel is not its concern —
     * it told us, and we went and asked.
     */
    private function heard(): Response
    {
        return response()->noContent(ResponseCode::HTTP_OK);
    }

    /**
     * An announcement not worth a call to the Courier: logged as the fields
     * it named — the reference, the Courier and the status it claimed — and
     * never as the body it arrived in, which is unauthenticated input of
     * unbounded size carrying whatever the sender put in it.
     */
    private function ignored(string $why, Courier $courier, Announcement $announcement): Response
    {
        Log::warning($why, [
            'courier' => $courier->value,
            'invoice_reference' => $announcement->invoiceReference,
            'claimed_status' => $announcement->claimedStatus,
        ]);

        return $this->heard();
    }
}
