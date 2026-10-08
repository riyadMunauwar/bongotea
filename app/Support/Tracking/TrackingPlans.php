<?php

namespace App\Support\Tracking;

use App\Models\Destination;
use App\Support\Settings\TrackingSettings;

/**
 * Where the store reports and what it reports there, answered in one place.
 *
 * The one reading of the store's tracking, so that the recorder and the
 * snippet builder cannot disagree. It answers with a Plan rather than a list
 * of Destinations because the Destinations, the events and whether the basket
 * travels are read together, and answering them separately is how two halves
 * of one event come to say different things.
 *
 * The store reports to the default Destination of each Provider. A
 * Destination the Merchant has switched off is not applicable at all, and so
 * produces no rows: switching one off is how a campaign break is taken. A
 * Destination that is *on* but has blank credentials does produce rows: the
 * Merchant fixing the token later is owed the events that accumulated
 * meanwhile, which is what the sweep's refusal path exists for.
 */
class TrackingPlans
{
    public function __construct(private readonly TrackingSettings $settings) {}

    /**
     * The store's Plan as it stands now.
     */
    public function current(): TrackingPlan
    {
        return TrackingPlan::of($this->defaults(), $this->settings);
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
}
