<?php

namespace App\Support\Tracking;

use App\Models\PlaceVisitCount;
use App\Models\VisitCount;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\TrackingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Where a Visit becomes a figure.
 *
 * The one Moment that touches no ad platform, and the only thing in the
 * system that writes the Visit aggregate. A Visit is **every load** of a
 * storefront page rather than a unique browser — the same way a Provider
 * counts the load it is told about — so the Merchant's own figure and the ad
 * platform's agree, and a Conversion Rate is Orders over the same denominator
 * either of them would use.
 *
 * Nothing is logged. One row per day is incremented for the whole store, so
 * the table stays the size of the calendar rather than the size of the
 * traffic. That is also why it can be kept forever: there is nothing in it
 * about anybody.
 *
 * The price of holding no rows is that a count cannot deduplicate: a beacon
 * that somehow arrives twice for one load is two Visits here and one event at
 * the Provider. It is the right trade.
 *
 * It counts nothing at all until the Merchant switches counting on.
 */
class VisitCounter
{
    public function __construct(private readonly TrackingSettings $settings) {}

    /**
     * Count one storefront load against today.
     *
     * Today is the Merchant's own day, because a Reporting Window cuts its
     * days at midnight in the Merchant's timezone and a figure bucketed at the
     * server's midnight would disagree with the Orders it is set against. The
     * timezone is read only once counting is on, so an installation that never
     * asked for this pays no query for it.
     *
     * The place the load was of is counted beside the store, never instead of
     * it: a load of no place is still a Visit to the store, so the store's
     * figure stands on its own and the places are a breakdown of it. Which
     * place an address is costs a query, so it too is asked only once
     * counting is on.
     */
    public function count(?string $address = null): void
    {
        if (! $this->isCounting()) {
            return;
        }

        $date = CarbonImmutable::now(app(GeneralSettings::class)->timezone)->toDateString();

        $this->add(VisitCount::query(), ['visited_on' => $date]);

        $place = Place::at($address);

        if ($place !== null) {
            $this->add(PlaceVisitCount::query(), [
                'visited_on' => $date,
                'place_kind' => $place->kind->value,
                'place_id' => $place->id,
            ]);
        }
    }

    /**
     * Whether a load is counted at all — the one place that decides it, asked
     * by `count()` and by the storefront deciding whether a load is worth a
     * beacon.
     */
    public function isCounting(): bool
    {
        return $this->settings->countsVisits;
    }

    /**
     * One more load against a day, as one statement wherever the row already
     * exists — which is every load after a day's first.
     *
     * An increment rather than a read-then-write, so two visitors arriving at
     * the same instant are two Visits. The insert that opens a day is the one
     * place two requests can collide, and the loser of that race is told so
     * by the unique index and simply increments what the winner wrote.
     *
     * An `upsert` would say this in one statement, but only by handing each
     * driver a different raw expression for "the value you already have";
     * this says it in terms every driver agrees on.
     *
     * @param  Builder<covariant Model>  $query
     * @param  array<string, string|int>  $key
     */
    private function add(Builder $query, array $key): void
    {
        if ($this->increment(clone $query, $key) > 0) {
            return;
        }

        /** In a savepoint whenever a transaction is open, because PostgreSQL aborts the whole transaction on a refused insert. */
        try {
            $query->withSavepointIfNeeded(fn () => (clone $query)->create([...$key, 'visits' => 1]));
        } catch (UniqueConstraintViolationException) {
            $this->increment(clone $query, $key);
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  array<string, string|int>  $key
     */
    private function increment(Builder $query, array $key): int
    {
        return $query->where($key)->increment('visits');
    }
}
