<?php

namespace App\Support\Tracking;

use App\Support\Settings\TrackingSettings;

/**
 * Where the store reports and what it reports there, answered in one place.
 *
 * The one reading of the store's tracking, so that the recorder and the
 * snippet builder cannot disagree. It answers with a Plan rather than a list
 * of Providers because the Providers, the events and whether the basket
 * travels are read together, and answering them separately is how two halves
 * of one event come to say different things.
 *
 * The store reports to every Provider the Merchant has switched on, to the
 * one account supplied for it in Settings (ADR-0032). A Provider switched off
 * produces no rows: switching one off is how a campaign break is taken. One
 * that is *on* but has blank credentials does produce rows: the Merchant
 * fixing the token later is owed the events that accumulated meanwhile,
 * which is what the sweep's refusal path exists for.
 */
class TrackingPlans
{
    public function __construct(private readonly TrackingSettings $settings) {}

    /**
     * The store's Plan as it stands now.
     */
    public function current(): TrackingPlan
    {
        return TrackingPlan::of($this->settings->enabledProviders(), $this->settings);
    }
}
