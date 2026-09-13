<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * The sweep over the parcels the couriers are carrying: it asks each Courier
 * where its Active Shipments stand and writes what it learned onto their
 * timelines.
 *
 * For two of the three couriers this is a backstop against a webhook that
 * never arrived. For Steadfast it is the only channel there is — it announces
 * nothing at all — so the resolution of a Steadfast timeline is exactly how
 * often this runs and how often that driver says it is worth asking again
 * (ADR-0019).
 *
 * How often a Courier is asked is the driver's own property and never one
 * constant here: a Courier that announces needs asking far less often than
 * one that does not, for the same reason a Provider owns its batch limit. The
 * two ages that *are* this class's own — when a parcel has gone on too long
 * to be left alone, and when it has gone on too long to keep asking about —
 * are constants here and never Settings, matching how Tracking's retention is
 * held (ADR-0020).
 *
 * Nothing here writes an `OrderStatus`, in either direction. A parcel that
 * came back does not cancel an Order and a Cancelled Order is still followed
 * to the door (ADR-0018).
 */
class CourierSweep
{
    /**
     * How long an attempt may go unresolved before somebody should look at
     * it: a week, which is well past any of these couriers' own promises and
     * well short of the month it takes for one to be given up on.
     *
     * Recorded on the Shipment and never acted on here. The sweep goes on
     * asking about a stuck parcel exactly as before — being stuck is a thing
     * to tell a User about, not a reason to stop looking.
     */
    public const STUCK_AFTER_SECONDS = 604800;

    /**
     * How long after booking a Courier stops being asked about a parcel at
     * all: a month.
     *
     * Not a state and not a decision — the Shipment stays Active and stays
     * counted in what the couriers are holding, because only a User writing
     * it off (Abandoned) may say a parcel is dead. All this stops is the
     * asking, so that a Merchant who never tidies up is not paying for a call
     * a year from now about a parcel nobody has thought about since.
     */
    public const GIVE_UP_AFTER_SECONDS = 2592000;

    /**
     * How much of a Courier's refusal is kept as the word the timeline is
     * deduplicated by.
     *
     * Well short of what the column holds, because the raw word is not
     * written alone: `ShipmentEvent::deduplicationIdFor()` spells it into a
     * string with the Courier's time after it, and *that* is what a
     * `string` column carries a unique key over. A sentence cut to the column
     * width here would be a row too long to write there.
     */
    private const REFUSAL_LIMIT = 200;

    /**
     * What one sweep did: how many parcels were asked about, how many
     * timeline entries that actually wrote, how many parcels no Courier would
     * account for, how many were newly flagged as stuck, and why each Courier
     * that was passed over was passed over.
     *
     * The reasons are keyed by Courier and are already worded for a Merchant
     * — `CourierSettings::refusal()` names the Courier, and so does a
     * Courier's silence — so the command prints them rather than restating
     * the rule.
     *
     * @return array{asked: int, recorded: int, refused: int, stuck: int, skipped: array<string, string>}
     */
    public function run(): array
    {
        /**
         * Before a Courier is asked anything, and whatever any of them
         * answers: how long a parcel has gone unresolved is the Merchant's
         * clock, not the couriers'.
         */
        $tally = ['asked' => 0, 'recorded' => 0, 'refused' => 0, 'stuck' => $this->flagStuck(), 'skipped' => []];

        foreach (Courier::cases() as $courier) {
            $refusal = $courier->refusal();

            /**
             * A Courier this installation cannot speak to is passed over
             * whole, before a parcel of its is read: its answers would every
             * one of them be the same missing credential, and an operator
             * hears the one wording the admin already gives them.
             */
            if ($refusal !== null) {
                $tally['skipped'][$courier->value] = $refusal;

                continue;
            }

            $this->poll($courier, $tally);
        }

        return $tally;
    }

    /**
     * Mark every attempt that has gone on long enough to need a human.
     *
     * Measured from when the Courier took the parcel, because that is when
     * the clock a Merchant cares about started — not from when the row was
     * written, and not from when it was last asked about.
     *
     * Written once and never cleared: a parcel that was stuck for a fortnight
     * and then arrived was still stuck, and that is the fact the Merchant
     * needs about that Courier. Done for every Active attempt whatever its
     * Courier, including one whose Courier has since been switched off — a
     * parcel nobody can ask about any more is the most stuck a parcel gets.
     */
    private function flagStuck(): int
    {
        return Shipment::query()
            ->active()
            ->whereNull('stuck_at')
            ->where('booked_at', '<=', now()->subSeconds(self::STUCK_AFTER_SECONDS))
            ->update(['stuck_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Ask one Courier about every parcel of its that is due, and write down
     * each answer.
     *
     * A Courier that goes quiet stops this Courier's run where it stands,
     * exactly as an unreachable Provider stops a Destination's
     * (`TrackingSweep::deliver()`): every later parcel would fail the same way
     * and cost a call to find out. Nothing is recorded against any of them, so
     * the next sweep picks the whole lot up again.
     *
     * @param  array{asked: int, recorded: int, refused: int, stuck: int, skipped: array<string, string>}  $tally
     */
    private function poll(Courier $courier, array &$tally): void
    {
        $driver = $courier->driver();

        foreach ($this->dueFor($courier, $driver->reAskIntervalSeconds()) as $shipment) {
            $tally['asked']++;

            $progress = $driver->track($shipment);

            if (! $progress->isFinal) {
                $tally['skipped'][$courier->value] = (string) $progress->error;

                Log::warning('A courier could not be asked where its parcels are, and will be asked again.', [
                    'courier' => $courier->value,
                    'invoice' => $shipment->invoice_reference,
                    'error' => $progress->error,
                ]);

                return;
            }

            $this->record($shipment, $progress, $tally);
        }
    }

    /**
     * The parcels this Courier is due to be asked about: still Active, still
     * within the horizon, and either never asked about or last asked about
     * longer ago than this driver says is worth leaving.
     *
     * A Shipment with no Consignment on it is not among them — there is
     * nothing to ask by. One with no `booked_at` is excluded by the horizon
     * comparison itself, which is the right answer while every Shipment is
     * written by a booking that has already happened (ADR-0020).
     *
     * @return LazyCollection<int, Shipment>
     */
    private function dueFor(Courier $courier, int $interval): LazyCollection
    {
        return Shipment::query()
            ->active()
            ->where('courier', $courier)
            ->whereNotNull('consignment_reference')
            ->where('booked_at', '>', now()->subSeconds(self::GIVE_UP_AFTER_SECONDS))
            ->where(fn (Builder $query) => $query
                ->whereNull('polled_at')
                ->orWhere('polled_at', '<=', now()->subSeconds($interval)))
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Write down what the Courier said about one parcel.
     *
     * Every state it reported becomes a Shipment Event, and the database is
     * what makes a history re-imported on every poll one timeline rather than
     * one timeline per poll (ADR-0019) — so this loops and never asks first.
     * Where the Courier reported anything, the last of it is where the parcel
     * stands now and the Shipment's own columns follow it.
     *
     * A Courier that answered and would not account for the parcel is written
     * down as Unknown in the Courier's own words, which is what Unknown is
     * for: somebody has to look at this. It is not terminal, so the parcel
     * goes on being asked about and is flagged stuck in its own time — and
     * the same refusal repeated every half hour is one timeline entry, for
     * the same reason a repeated status is.
     *
     * @param  array{asked: int, recorded: int, refused: int, stuck: int, skipped: array<string, string>}  $tally
     */
    private function record(Shipment $shipment, ShipmentProgress $progress, array &$tally): void
    {
        if (! $progress->answered) {
            $tally['refused']++;

            Log::warning('A courier would not account for a parcel it was asked about.', [
                'courier' => $shipment->courier->value,
                'invoice' => $shipment->invoice_reference,
                'consignment' => $shipment->consignment_reference,
                'error' => $progress->error,
            ]);
        }

        $reported = $progress->answered
            ? $progress->reported
            : [new ReportedStatus(ShipmentStatus::Unknown, $this->asAWord((string) $progress->error))];

        foreach ($reported as $state) {
            if ($shipment->recordEvent($state->status, $state->rawStatus, $state->occurredAt)->wasRecentlyCreated) {
                $tally['recorded']++;
            }
        }

        $latest = $reported === [] ? null : $reported[array_key_last($reported)];

        $shipment->fill(array_merge(
            ['polled_at' => now()],
            $latest === null ? [] : ['status' => $latest->status, 'raw_status' => $latest->rawStatus],
        ))->save();
    }

    /**
     * A Courier's refusal, cut to a length a timeline can be deduplicated by.
     *
     * Kept as words rather than reduced to a code, because the column is
     * printed verbatim on the timeline and "no such consignment" is the whole
     * of what an operator needs before they ring somebody. The answer it came
     * in is in the log beside it.
     */
    private function asAWord(string $refusal): string
    {
        return Str::limit($refusal, self::REFUSAL_LIMIT, '');
    }
}
