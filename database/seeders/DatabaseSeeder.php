<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * An Owner rather than a User, because a User holding no Role is refused
     * at the panel door: a seeded installation whose only account cannot open
     * the admin is not a usable one. This is the development counterpart of
     * `php artisan giopages:owner`, which is how a real installation gets its
     * first one.
     */
    public function run(PermissionCatalogue $catalogue): void
    {
        $catalogue->sync();

        $owner = User::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $owner->assignRole(Role::owner());
    }
}
