<?php

namespace App\Console\Commands;

use App\Support\Access\PermissionCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Make the `permissions` table say what the catalogue says.
 *
 * Nothing is incorrect on an installation that never runs this: what exists is
 * what the enum says, and granting a Permission writes whatever row it needs
 * (ADR-0026). GioPages is self-hosted with no deploy pipeline we control, so a
 * correctness that depended on a merchant remembering a command would be a
 * gate nobody could open.
 *
 * What it is for is pre-creating the rows on a fresh installation, pruning
 * what a release removed, and repairing one by hand.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permission:sync';

    protected $description = 'Register every Permission the system declares, and prune rows for Permissions it no longer has';

    public function handle(PermissionCatalogue $catalogue): int
    {
        ['created' => $created, 'removed' => $removed] = $catalogue->sync();

        $this->report('Registered', $created);
        $this->report('Removed', $removed);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $names
     */
    private function report(string $verb, array $names): void
    {
        $count = count($names);
        $line = "{$verb} {$count} ".Str::plural('Permission', $count).'.';

        if ($count > 0) {
            $line .= ' '.implode(', ', $names);
        }

        $this->components->info($line);
    }
}
