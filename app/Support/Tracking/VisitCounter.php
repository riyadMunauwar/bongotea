<?php

namespace App\Support\Tracking;

use App\Models\Page;
use App\Models\VisitCount;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\TrackingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Where a Visit becomes a figure.
 *
 * The one Moment that touches no ad platform, and the only thing in the
 * system that writes the Visit aggregate. A Visit is **every load** of a
 * published Page rather than a unique browser — the same way a Provider
 * counts the load it is told about — so the Merchant's own figure and the ad
 * platform's agree, and a Conversion Rate is Orders over the same denominator
 * either of them would use.
 *
 * Nothing is logged. One row per Page per day is incremented, so a busy Page
 * costs one small update per load and the table stays the size of the
 * calendar rather than the size of the traffic. That is also why it can be
 * kept forever: there is nothing in it about anybody.
 *
 * The price of holding no rows is that a count cannot deduplicate: the outbox
 * refuses a second report of one load because it keeps that load's identifier,
 * and a figure that kept identifiers would be the log this is not. So a beacon
 * that somehow arrives twice for one load is two Visits here and one event at
 * the Provider. It is the right trade — the beacon is sent once, fire and
 * forget, and the alternative is a table the size of the traffic to protect a
 * figure against a rounding error.
 *
 * It counts nothing at all until the Merchant switches counting on. The gate
 * is here rather than at the beacon because this is the only thing the switch
 * decides — whether the load is counted — and the beacon has its own reason to
 * fire whenever a Page reports somewhere.
 */
class VisitCounter
{
    public function __construct(private readonly TrackingSettings $settings) {}

    /**
     * Count one load of a published Page against today.
     *
     * Today is the Merchant's own day, because a Reporting Window cuts its
     * days at midnight in the Merchant's timezone and a figure bucketed at the
     * server's midnight would disagree with the Orders it is set against. The
     * timezone is read only once counting is on, so an installation that never
     * asked for this pays no query for it.
     */
    public function count(Page $page): void
    {
        if (! $this->settings->countsVisits) {
            return;
        }

        $this->add($page, CarbonImmutable::now(app(GeneralSettings::class)->timezone)->toDateString());
    }

    /**
     * One more load against a Page's day, as one statement wherever the row
     * already exists — which is every load after a day's first.
     *
     * An increment rather than a read-then-write, so two visitors arriving at
     * the same instant are two Visits: the database adds to whatever it holds,
     * and neither request has to have seen the other's value. The insert that
     * opens a day is the one place two requests can collide, and the loser of
     * that race is told so by the unique index and simply increments what the
     * winner wrote.
     *
     * An `upsert` would say this in one statement, but only by handing each
     * driver a different raw expression for "the value you already have";
     * this says it in terms every driver agrees on.
     */
    private function add(Page $page, string $date): void
    {
        if ($this->increment($page, $date) > 0) {
            return;
        }

        try {
            VisitCount::query()->create([
                'page_id' => $page->getKey(),
                'visited_on' => $date,
                'visits' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->increment($page, $date);
        }
    }

    private function increment(Page $page, string $date): int
    {
        return VisitCount::query()
            ->where('page_id', $page->getKey())
            ->where('visited_on', $date)
            ->increment('visits');
    }
}
