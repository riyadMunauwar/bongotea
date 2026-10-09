<?php

namespace App\Console\Commands;

use Database\Seeders\CatalogueSeeder;
use Database\Seeders\StorefrontSeeder;
use Illuminate\Console\Command;

/**
 * Open a fresh installation ready to sell, looking like the mockup.
 *
 * Registers the Permissions, then seeds the catalogue — so the Pages' live
 * Blocks have Products to draw — and then the Pages and Menus. It only ever
 * adds what is missing, so it is safe to run again after a failed or
 * partial install, and nothing a Merchant has edited, renamed or deleted
 * since is put back (ADR-0030). It makes no User: an Owner is
 * `giopages:owner`'s job.
 */
class InstallCommand extends Command
{
    protected $signature = 'bongotea:install';

    protected $description = 'Create the System Pages, the starter Pages, Menus and catalogue a fresh store opens with, adding only what is missing';

    public function handle(): int
    {
        $this->call('permission:sync');

        foreach ([CatalogueSeeder::class, StorefrontSeeder::class] as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $this->components->info('Bongotea is installed. Make the first Owner with: php artisan giopages:owner {email}');

        return self::SUCCESS;
    }
}
