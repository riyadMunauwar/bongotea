<?php

namespace App\Console\Commands;

use App\Support\Couriers\CourierSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Hand each Courier the parcels a bulk booking wrote down for it, then ask it
 * where the parcels it is already carrying stand and write what it says onto
 * their timelines.
 *
 * Scheduled every five minutes in `routes/console.php` beside the payment and
 * tracking sweeps; how often any one parcel is actually asked about is its
 * Courier's own property and not this cadence. Safe to run by hand at any
 * time: a parcel is offered exactly once because it is offered from its own
 * written row, a parcel is asked about only when its Courier says it is worth
 * asking again, the same answer twice writes one timeline entry, and a
 * Courier that cannot be reached leaves its parcels exactly as it found them.
 */
class SweepCouriersCommand extends Command
{
    protected $signature = 'couriers:sweep';

    protected $description = 'Ask each Courier where its Active parcels stand, and record what it says';

    public function handle(CourierSweep $sweep): int
    {
        [
            'placed' => $placed,
            'rejected' => $rejected,
            'asked' => $asked,
            'recorded' => $recorded,
            'refused' => $refused,
            'stuck' => $stuck,
            'skipped' => $skipped,
        ] = $sweep->run();

        $this->components->info("Handed over {$placed} ".Str::plural('parcel', $placed).'.');

        if ($rejected > 0) {
            $this->components->warn("Rejected {$rejected} ".Str::plural('parcel', $rejected).' at booking.');
        }

        $this->components->info("Asked about {$asked} ".Str::plural('parcel', $asked).'.');
        $this->components->info("Recorded {$recorded} timeline ".Str::plural('entry', $recorded).'.');
        $this->components->info("Flagged {$stuck} stuck ".Str::plural('parcel', $stuck).'.');

        if ($refused > 0) {
            $this->components->warn("Refused {$refused} ".Str::plural('parcel', $refused).'.');
        }

        /** The Courier's own wording, which already names the Courier, so the operator reads here what the admin would tell them. */
        foreach ($skipped as $reason) {
            $this->components->warn("Skipped a courier: {$reason}");
        }

        return self::SUCCESS;
    }
}
