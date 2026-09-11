<?php

namespace App\Support\Settings;

use App\Enums\TrackingProvider;
use App\Support\Tracking\EventSwitches;

/**
 * What the installation decides about tracking once: which of each Provider's
 * events fire on a Page that has said nothing about them, and how hard the
 * beacon may be leaned on.
 *
 * The half of a Page's Tracking Config that is not per Page. A Merchant who
 * decides they want no `ViewContent` anywhere says it here rather than on
 * every Page, and a Page that wants it anyway says so on its own tab — the
 * per-Page word wins, and silence inherits.
 *
 * Deliberately not here: anything about `user_data`. Meta computes match
 * quality per dataset rather than per Page, so a switch for it would be a
 * Merchant quietly degrading every Page sharing a Destination; what is
 * offered instead is the per-Page `custom_data` suppression, which costs
 * nothing outside the Page that asks for it.
 */
class TrackingSettings extends SettingsGroup
{
    /**
     * What is fired for a Page that says nothing, by Provider and then by the
     * Provider's own name for the event.
     *
     * An event absent here is on. That is the whole of why this can be one
     * loose array rather than a property per event: a Provider that gains a
     * fifth event gains a switch on this page and on every Tracking tab with
     * no data migration and no edit here, and an installation that has never
     * opened this page reports everything, which is what it did before this
     * group existed.
     *
     * @var array<string, array<string, bool>>
     */
    public array $eventDefaults = [];

    /**
     * Moments one address may report in a minute before the beacon refuses it.
     *
     * Set well above what a real visitor produces — one beacon per page load
     * — because the addresses this counts are shared: a carrier's NAT puts
     * thousands of genuine visitors behind one, and this market browses from
     * mobile almost entirely. Being refused costs the redundancy and not the
     * event, since the browser's own Pixel has already fired, which is what
     * makes a generous limit the cheap side to be wrong on.
     */
    public int $beaconRateLimit;

    /**
     * Whether every load of a published Page is counted as a Visit.
     *
     * Off until a Merchant asks for it. Counting is an increment on the path
     * of a page load, and this is a product served from shared hosting — a
     * write per visit that nobody asked for is the wrong default, however
     * small it is. Switching it on is what turns the Visits tile and the
     * leaderboard's Conversion Rate from a sentence into a figure.
     *
     * It owes nothing to any ad platform: a Page with no Destination anywhere
     * in the installation still carries the beacon while this is on, and still
     * counts. That is the whole of why the Visit Moment is worth having on its
     * own.
     */
    public bool $countsVisits;

    public function __construct()
    {
        $this->beaconRateLimit = (int) config('giopages.tracking.beacon_rate_limit');
        $this->countsVisits = (bool) config('giopages.tracking.counts_visits');
    }

    public static function key(): string
    {
        return 'tracking';
    }

    /**
     * Whether a Page that has said nothing about this event reports it.
     *
     * On unless the installation has said otherwise, so an event that nobody
     * has an opinion about fires — the same direction the consent hook
     * defaults in, and for the same reason: the failure nobody notices is
     * reporting silently switched off.
     */
    public function reportsByDefault(TrackingProvider $provider, string $event): bool
    {
        return $this->eventDefaults[$provider->value][$event] ?? true;
    }

    /**
     * Two guards the base cannot give this group, in the shape
     * `GeneralSettings::with()` established for the timezone.
     *
     * The first: a stored `eventDefaults` that is not the shape this promises
     * — a scalar from a hand-edited row, a null from a form, a nested list —
     * reads as nothing having been decided rather than as a `TypeError` from
     * `load()` on every request. The base coerces scalars against a declared
     * type and leaves arrays alone, which is right for every other property
     * and not enough for this one, and it has to happen *before* the
     * assignment rather than after it.
     *
     * The second is at the rate limit below.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        if (array_key_exists('eventDefaults', $values)) {
            $values['eventDefaults'] = self::defaults($values['eventDefaults']);
        }

        $group = parent::with($values);

        /**
         * A limit of nothing is not a limit, it is the beacon switched off
         * for everyone — and silently, which is the one failure this whole
         * design is arranged to avoid. A row holding a null (or a hand edit
         * holding a zero) reads as the limit this group already has, which on
         * a fresh group is the config default. The page's own field refuses
         * such a value up front; this is for the row that never went through
         * it, as the timezone guard on `GeneralSettings` is.
         */
        if ($group->beaconRateLimit < 1) {
            $group->beaconRateLimit = $this->beaconRateLimit;
        }

        return $group;
    }

    /**
     * The stored switches: a form hands its toggles over as `true`/`false`,
     * and a row may hold whatever an older shape or a hand edit left. Both
     * arrive here, and anything that is not an answer is nothing decided.
     *
     * @return array<string, array<string, bool>>
     */
    private static function defaults(mixed $stored): array
    {
        return EventSwitches::read($stored, fn (mixed $choice): ?bool => $choice === null ? null : (bool) $choice);
    }
}
