<?php

namespace App\Support\Tracking;

use App\Models\Destination;
use App\Models\Page;

/**
 * Which Destinations a Page reports to.
 *
 * The one place that answers it, so that the recorder, the snippet builder
 * and the Tracking tab cannot disagree. Today the answer is the Destination
 * marked default for each Provider: a Page picks its own from #115 onwards,
 * and blank there will still mean "the default for that Provider", which is
 * what this already returns.
 *
 * A Destination the Merchant has switched off is not applicable at all, and
 * so produces no rows: switching one off is how a campaign break is taken,
 * and rows written to a paused Destination would either pile up undelivered
 * or flood out the moment it came back. A Destination that is *on* but has
 * blank credentials is a different case and does produce rows — the Merchant
 * fixing the token later is owed the events that accumulated meanwhile, which
 * is what the sweep's refusal path exists for.
 */
class TrackingDestinations
{
    /**
     * @return list<Destination>
     */
    public function forPage(?Page $page): array
    {
        return $this->defaults();
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
