<?php

namespace App\Support\Tracking;

use App\Enums\TrackingEventState;
use App\Enums\TrackingProvider;
use App\Models\TrackingContext;
use App\Models\TrackingEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Where tracking data stops accumulating.
 *
 * Every retention here is bound to the purpose the data serves and is a
 * constant rather than a Setting, the way `PaymentSweep`'s two ages are: a
 * Merchant asked to choose how long an IP address is kept is being asked a
 * question they have no way to answer, and the right answer is the same on
 * every installation.
 *
 * **Nothing Pending is ever deleted.** A Merchant whose cron has been broken
 * for a week must find their events piling up and then all go out on the
 * first run after it is fixed — that is the whole point of an outbox, and a
 * prune that tidied away the backlog would turn a broken scheduler into
 * silently lost reporting. The only thing that settles a Pending row is the
 * sweep, which expires one the Provider would no longer accept.
 *
 * **The Visit aggregate is not touched, and there is nothing to roll up into
 * it first.** `VisitCounter` writes `visit_counts` at report time, one row per
 * Merchant-day for the whole store, so the figure already exists before this
 * ever runs and survives the rows below it being deleted. It is not derived
 * from the outbox and must never be: an outbox row exists only where a
 * Provider is switched on, so a store reporting nowhere would count nothing, and one
 * reporting two provider events for one load would count twice. Anything added here
 * that a figure is derived from must be derived **before** the delete that
 * removes it, in this same method — a prune that runs before its own rollup
 * loses a month of history and does it invisibly.
 */
class TrackingPrune
{
    /**
     * How long a delivered outbox row is kept: long enough to answer "did
     * last week's events go out", and no longer. These are the bulk of the
     * table — one row per provider event per Provider per Moment — so this
     * is the age that decides how large it gets.
     */
    public const SENT_RETENTION_DAYS = 7;

    /**
     * How long a row that never arrived is kept. Longer, because a refusal is
     * the one outcome somebody has to read and the diagnostics screen is
     * where they read it, and affordable, because a Provider that refuses
     * everything is a defect somebody fixes rather than a steady rate.
     */
    public const DIAGNOSTIC_RETENTION_DAYS = 30;

    /**
     * Rows deleted per statement. A Page under paid traffic writes an outbox
     * row per provider event per load, so an unbounded `DELETE` here is a
     * table lock of unknown length on the shared hosting this product is
     * built for. Batched, it is many short statements instead of one long
     * one, and a run that is interrupted has still done most of its work.
     */
    private const BATCH_SIZE = 1000;

    /**
     * How long the browser's word about a visit is worth keeping.
     *
     * **Asked of the Providers rather than written down as seven days.** A
     * Tracking Context exists for exactly one thing: to be read back when the
     * Verification that says Paid reports a `Purchase` with no browser
     * present. Past the Provider's own acceptance window that event would be
     * refused whatever we sent, so the data cannot serve its only purpose and
     * is pure liability — an IP address and a User-Agent held for nothing.
     * Facebook's window is seven days, which is where the number comes from;
     * deriving it means the number and the reason cannot drift apart, and a
     * Provider added later with a different window moves this on its own.
     */
    public static function contextRetentionSeconds(): int
    {
        return max(array_map(
            fn (TrackingProvider $provider): int => $provider->driver()->acceptanceWindowSeconds(),
            TrackingProvider::cases(),
        ));
    }

    /**
     * How many rows of each kind were deleted.
     *
     * @return array{sent: int, undelivered: int, contexts: int}
     */
    public function run(): array
    {
        return [
            'sent' => $this->pruneOutbox([TrackingEventState::Sent], self::SENT_RETENTION_DAYS),
            'undelivered' => $this->pruneOutbox(TrackingEventState::undelivered(), self::DIAGNOSTIC_RETENTION_DAYS),
            'contexts' => $this->pruneContexts(),
        ];
    }

    /**
     * Settled outbox rows past their age.
     *
     * Cut on `updated_at`, which the sweep writes at the moment it settles a
     * row and nothing touches afterwards, so the age is "how long since we
     * learned what happened to this" for all three settled states alike —
     * `sent_at` would say the same thing for Sent rows and nothing at all for
     * the other two.
     *
     * @param  list<TrackingEventState>  $states
     */
    private function pruneOutbox(array $states, int $days): int
    {
        return $this->deleteInBatches(
            TrackingEvent::query()
                ->whereIn('state', $states)
                ->where('updated_at', '<=', now()->subDays($days))
        );
    }

    /**
     * Tracking Contexts past the window in which the Moment they were kept
     * for could still be reported. Cut on `created_at`: a row is written once
     * at Capture and never rewritten, and it is the visit's own age that
     * decides whether a `Purchase` built from it would still be accepted.
     */
    private function pruneContexts(): int
    {
        return $this->deleteInBatches(
            TrackingContext::query()
                ->where('created_at', '<=', now()->subSeconds(self::contextRetentionSeconds()))
        );
    }

    /**
     * Delete everything the query matches, a batch of keys at a time. The
     * query is re-read each round rather than paged through, because the rows
     * it matched last round are gone by then.
     *
     * @param  Builder<Model>  $query
     */
    private function deleteInBatches(Builder $query): int
    {
        $deleted = 0;

        while (true) {
            $keys = (clone $query)->orderBy('id')->limit(self::BATCH_SIZE)->pluck('id');

            if ($keys->isEmpty()) {
                return $deleted;
            }

            $deleted += $query->getModel()->newQuery()->whereKey($keys)->delete();
        }
    }
}
