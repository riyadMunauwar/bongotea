<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

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
 * What one question to one Courier comes to is `CourierPoller`'s and not
 * this class's, because a webhook claiming something changed puts the same
 * question and must write the answer down the same way (ADR-0019). What is
 * left here is the sweeping: which parcels are due, when to stop asking, and
 * what the whole run came to.
 *
 * Nothing here writes an `OrderStatus`, in either direction. A parcel that
 * came back does not cancel an Order and a Cancelled Order is still followed
 * to the door (ADR-0018).
 */
class CourierSweep
{
    public function __construct(private readonly CourierPoller $poller) {}

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
        foreach ($this->dueFor($courier, $courier->driver()->reAskIntervalSeconds()) as $shipment) {
            $tally['asked']++;

            $polled = $this->poller->ask($shipment);

            if (! $polled->progress->isFinal) {
                $tally['skipped'][$courier->value] = (string) $polled->progress->error;

                Log::warning('A courier could not be asked where its parcels are, and will be asked again.', [
                    'courier' => $courier->value,
                    'invoice' => $shipment->invoice_reference,
                    'error' => $polled->progress->error,
                ]);

                return;
            }

            $tally['recorded'] += $polled->recorded;
            $tally['refused'] += $polled->progress->answered ? 0 : 1;
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
}
