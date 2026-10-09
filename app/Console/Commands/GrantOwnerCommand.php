<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Illuminate\Console\Command;

/**
 * Make a User the Owner of this installation.
 *
 * The one route into the panel that does not go through the panel, and that
 * is deliberate (ADR-0024). It is how a fresh installation gets its first
 * Owner — a future installer, terminal or GUI, calls this — and it is the
 * whole of the answer to "the only Owner has left the company", which is
 * otherwise unanswerable from inside a panel nobody can reach.
 */
class GrantOwnerCommand extends Command
{
    protected $signature = 'bongotea:owner {email : The email address of the User to make an Owner}';

    protected $description = 'Grant the Owner Role to the User registered under the given email address';

    public function handle(PermissionCatalogue $catalogue): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No User is registered under {$email}.");

            return self::FAILURE;
        }

        if ($user->isOwner()) {
            $this->components->info("{$user->name} ({$email}) is already an Owner.");

            return self::SUCCESS;
        }

        $user->assignRole(Role::owner());

        $catalogue->forget();

        $this->components->info("{$user->name} ({$email}) is now an Owner.");

        return self::SUCCESS;
    }
}
