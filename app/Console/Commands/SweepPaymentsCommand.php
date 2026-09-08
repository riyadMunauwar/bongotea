<?php

namespace App\Console\Commands;

use App\Support\Payments\PaymentSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ask the gateways about every Pending Payment old enough to be worth asking
 * about, and give up on the ones older than a day.
 *
 * Scheduled every five minutes in `routes/console.php`; the rules are
 * `PaymentSweep`'s. Safe to run by hand at any time, because a Verification
 * settles a Payment once and asks about a settled one never.
 */
class SweepPaymentsCommand extends Command
{
    protected $signature = 'payments:sweep';

    protected $description = 'Re-verify Pending Payments with their gateways, and mark those older than a day Expired';

    public function handle(PaymentSweep $sweep): int
    {
        ['verified' => $verified, 'expired' => $expired] = $sweep->run();

        $this->components->info("Verified {$verified} pending ".Str::plural('Payment', $verified).'.');
        $this->components->info("Expired {$expired} ".Str::plural('Payment', $expired).'.');

        return self::SUCCESS;
    }
}
