<?php

namespace App\Console\Commands;

use App\Support\Tracking\TrackingPrune;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Delete the tracking rows that can no longer serve the purpose they were
 * written for.
 *
 * Scheduled daily in `routes/console.php`; the ages are `TrackingPrune`'s and
 * the reasoning for each is there. Safe to run by hand at any time, and safe
 * to run when the sweep is behind: nothing Pending is touched, so a backlog
 * waiting on a scheduler that has been broken for a week is still there when
 * it is fixed.
 *
 * The Visit aggregate is deliberately not pruned and is not rolled up here
 * either — it is written as the Visits happen, so it is already complete
 * before this runs. See `TrackingPrune` for why deriving it from these rows
 * would be wrong as well as late.
 */
class PruneTrackingCommand extends Command
{
    protected $signature = 'tracking:prune';

    protected $description = 'Delete delivered tracking events, old failures and Tracking Contexts past their retention';

    public function handle(TrackingPrune $prune): int
    {
        ['sent' => $sent, 'undelivered' => $undelivered, 'contexts' => $contexts] = $prune->run();

        $this->components->info("Deleted {$sent} delivered ".Str::plural('event', $sent).'.');
        $this->components->info("Deleted {$undelivered} undelivered ".Str::plural('event', $undelivered).'.');
        $this->components->info("Deleted {$contexts} tracking ".Str::plural('context', $contexts).'.');

        return self::SUCCESS;
    }
}
