<?php

namespace App\Console\Commands;

use App\Support\Tracking\TrackingSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Deliver everything waiting in the tracking outbox.
 *
 * Scheduled every five minutes in `routes/console.php` beside the payment
 * sweep, and safe to run by hand at any time: a delivered row is never sent
 * again, and a Provider that cannot be reached leaves its rows exactly as it
 * found them.
 *
 * A Merchant whose cron was broken for a day sees rows piling up rather than
 * events vanishing, and all of them go out on the first run after it is
 * fixed — up to the point where the Provider will no longer accept them.
 */
class SweepTrackingCommand extends Command
{
    protected $signature = 'tracking:sweep';

    protected $description = 'Deliver pending tracking events to their Destinations, and expire those too old to be accepted';

    public function handle(TrackingSweep $sweep): int
    {
        ['sent' => $sent, 'failed' => $failed, 'expired' => $expired, 'pending' => $pending, 'skipped' => $skipped] = $sweep->run();

        $this->components->info("Sent {$sent} tracking ".Str::plural('event', $sent).'.');
        $this->components->info("Failed {$failed} ".Str::plural('event', $failed).'.');
        $this->components->info("Expired {$expired} ".Str::plural('event', $expired).'.');
        $this->components->info("Left {$pending} ".Str::plural('event', $pending).' pending.');

        /** The Provider's own wording, which already names the Destination, so the operator reads here what the admin would tell them. */
        foreach ($skipped as $refusal) {
            $this->components->warn("Skipped a destination: {$refusal}");
        }

        return self::SUCCESS;
    }
}
