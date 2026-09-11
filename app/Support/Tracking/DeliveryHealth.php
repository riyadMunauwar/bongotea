<?php

namespace App\Support\Tracking;

use App\Enums\TrackingEventState;
use App\Models\TrackingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Whether reporting is actually happening, in the four answers an operator
 * arrives with: how much is waiting, how much never got there, when the sweep
 * last ran, and what went wrong.
 *
 * `Delivery` is one attempt's outcome; this is the standing picture of all of
 * them. Nothing here decides anything — the states are the sweep's and the
 * retention is `TrackingPrune`'s — it is the one place the diagnostics
 * screen's questions are asked, so a widget labels and never counts.
 *
 * **When the sweep last ran is recorded, not inferred.** The most recent
 * `sent_at` would answer a different question: a week with no Orders and a
 * week with a dead scheduler look identical through it, and telling those two
 * apart is the entire reason this figure is on the screen. So the sweep says
 * so every time it finishes, whether or not it found anything to do.
 *
 * It is kept in the cache rather than in a table, because it is operational
 * state and not a domain record: it is true of this installation right now,
 * nothing reads it back later, and losing it costs one five-minute wait for
 * the next sweep to write it again. The scheduler already depends on the
 * cache store for the sweep's own overlap mutex, so this adds no new
 * dependency on it.
 */
class DeliveryHealth
{
    /**
     * How long after a sweep the absence of another one is worth pointing at.
     *
     * The sweep is scheduled every five minutes, so three missed runs is a
     * scheduler that has stopped rather than a slow minute. Generous on
     * purpose: a false alarm here teaches an operator to ignore the one
     * signal that catches a broken cron.
     */
    public const STALE_AFTER_SECONDS = 900;

    private const SWEPT_AT_KEY = 'tracking.swept_at';

    /**
     * A sweep has just finished. Called whatever it found, because an empty
     * outbox is not the same thing as a scheduler that never fired.
     */
    public function sweptNow(): void
    {
        Cache::forever(self::SWEPT_AT_KEY, now()->toIso8601String());
    }

    /**
     * When the sweep last finished, or null where no sweep has ever been
     * recorded on this installation — a scheduler that has never run, or a
     * cache that was cleared since it last did.
     */
    public function lastSweptAt(): ?CarbonImmutable
    {
        return $this->instant(Cache::get(self::SWEPT_AT_KEY));
    }

    /**
     * Whether the sweep has been quiet for long enough to be worth saying so.
     * A sweep that has never run counts as stale: on an installation holding
     * Destinations, nothing having reported is exactly the alarm.
     */
    public function isStale(): bool
    {
        $swept = $this->lastSweptAt();

        return $swept === null || $swept->lte(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    /** Events written down and not yet delivered. */
    public function waiting(): int
    {
        return TrackingEvent::query()->where('state', TrackingEventState::Pending)->count();
    }

    /**
     * When the oldest waiting event happened, or null where nothing is
     * waiting. A queue that is minutes old is a queue being worked; one that
     * is days old is the sign of a Destination nobody can deliver to.
     */
    public function oldestWaitingAt(): ?CarbonImmutable
    {
        return $this->instant(TrackingEvent::query()
            ->where('state', TrackingEventState::Pending)
            ->min('occurred_at'));
    }

    /**
     * Events that never arrived, in total and split by which way they went.
     *
     * The total is what the failures table lists, so the tile above it and
     * the rows below it are counting one set — a tile saying nothing has
     * failed over a table of failures is worse than no tile. The split is
     * what the operator does about it: a refusal is a credential or a payload
     * somebody must fix, and an expiry is a Destination that was left broken
     * for longer than the Provider's window.
     *
     * @return array{total: int, refused: int, expired: int}
     */
    public function undelivered(): array
    {
        $refused = $this->failures()->where('state', TrackingEventState::Failed)->count();
        $expired = $this->failures()->where('state', TrackingEventState::Expired)->count();

        return ['total' => $refused + $expired, 'refused' => $refused, 'expired' => $expired];
    }

    /**
     * The events that never arrived, ready to be listed.
     *
     * Refused and Expired together, named once by
     * `TrackingEventState::undelivered()`, because both are an event a
     * Provider was owed and never got.
     *
     * **Bounded by the same age the prune keeps them for**, so the screen's
     * "kept for thirty days" is true of what it is drawing even on the
     * installation where the prune is the cron that is broken — which is
     * precisely the installation reading this screen.
     *
     * @return Builder<TrackingEvent>
     */
    public function failures(): Builder
    {
        return TrackingEvent::query()
            ->whereIn('state', TrackingEventState::undelivered())
            ->where('updated_at', '>', now()->subDays(TrackingPrune::DIAGNOSTIC_RETENTION_DAYS))
            ->with('destination');
    }

    /**
     * A stored instant as one, or null. Both callers read a raw column value
     * — a cache entry and an aggregate — which arrives as a string from every
     * driver and as nothing at all where there is no row.
     */
    private function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
