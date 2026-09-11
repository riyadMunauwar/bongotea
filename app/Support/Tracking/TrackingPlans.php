<?php

namespace App\Support\Tracking;

use App\Models\Destination;
use App\Models\Page;
use App\Support\Settings\TrackingSettings;

/**
 * Where a Page reports and what it reports there, answered in one place.
 *
 * The one reading of a Tracking Config, so that the recorder, the snippet
 * builder and the Tracking tab's own preview cannot disagree. It answers with
 * a Plan rather than a list of Destinations because all three questions — the
 * Destinations, the events, and whether the basket travels — are read from
 * the same Config, and answering them separately is how two halves of one
 * event come to say different things.
 *
 * A Page picks a Destination or says nothing. Saying nothing is the default
 * Destination for each Provider, which is what an installation with one
 * Facebook account never has to think about. Picking one replaces that
 * Provider's default and leaves every other Provider's alone, so a Page
 * pointed at a client's Facebook account still reports to the installation's
 * TikTok when there is one.
 *
 * A Destination the Merchant has switched off is not applicable at all, and
 * so produces no rows: switching one off is how a campaign break is taken,
 * and rows written to a paused Destination would either pile up undelivered
 * or flood out the moment it came back. That holds for a Page's own pick as
 * much as for a default — a Merchant who pointed this Page somewhere and then
 * paused it meant to pause it. A Destination that is *on* but has blank
 * credentials is a different case and does produce rows: the Merchant fixing
 * the token later is owed the events that accumulated meanwhile, which is
 * what the sweep's refusal path exists for.
 *
 * A pick naming a Destination that has since been deleted is not an error and
 * not a Page that stops reporting: it falls back to the default, because a
 * tidy-up in Settings must never silently switch off a live campaign.
 */
class TrackingPlans
{
    public function __construct(private readonly TrackingSettings $settings) {}

    /**
     * The Plan for a Page — or for no Page at all, which is an Order whose
     * Page has been deleted for good: the defaults and the installation's own
     * event choices, because the Purchase matters more than the Page does.
     */
    public function forPage(?Page $page): TrackingPlan
    {
        return $this->forConfig($page?->tracking_config ?? TrackingConfig::default());
    }

    /**
     * The Plan a Config describes, whether or not it has ever been saved.
     *
     * The seam the Tracking tab's preview reads through: a Merchant is shown
     * what the Page they are looking at will send, from the same reading the
     * Moment will take, rather than from a second implementation of these
     * rules that could drift from it.
     */
    public function forConfig(TrackingConfig $config): TrackingPlan
    {
        return TrackingPlan::of($this->destinationsFor($config), $config, $this->settings);
    }

    /**
     * Every Provider's default Destination, switched on.
     *
     * @return list<Destination>
     */
    public function defaults(): array
    {
        return Destination::query()->defaulted()->orderBy('id')->get()->all();
    }

    /**
     * @return list<Destination>
     */
    private function destinationsFor(TrackingConfig $config): array
    {
        $picked = $this->picked($config);

        if ($picked === null) {
            return $this->defaults();
        }

        /**
         * The pick stands in for its own Provider's default whether or not it
         * can be reported to, so a Page pointed at a switched-off Destination
         * takes the campaign break the Merchant asked for rather than
         * silently reporting somewhere else.
         */
        $destinations = array_values(array_filter(
            $this->defaults(),
            fn (Destination $default): bool => $default->provider !== $picked->provider,
        ));

        return $picked->enabled ? [$picked, ...$destinations] : $destinations;
    }

    /**
     * The Destination this Config names, or null where it names none or names
     * one that no longer exists.
     */
    private function picked(TrackingConfig $config): ?Destination
    {
        if ($config->destinationId === null) {
            return null;
        }

        return Destination::query()->find($config->destinationId);
    }
}
