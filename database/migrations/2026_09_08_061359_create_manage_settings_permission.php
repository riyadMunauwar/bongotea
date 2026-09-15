<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The literal rather than a reference into `app/`, so this stays runnable
     * whatever the application is later refactored into. It agreed with
     * `SettingsCluster::PERMISSION` when it ran; the catalogue now lives in
     * `App\Enums\Permission`, and nothing needs the two to match any more —
     * the row this creates is one `permission:sync` would create anyway, and a
     * name no case claims is one it prunes.
     */
    private const PERMISSION = 'manage settings';

    /**
     * Bring in the one permission Settings is gated by, and hand it to every
     * User who is already here.
     *
     * Every User was an admin before this permission existed, so an upgrade
     * that created the gate without opening it for them would lock a Merchant
     * out of their own Settings. Granted directly rather than through a role:
     * roles were not in the product yet, and when they arrive they will carry
     * this same permission with no change here or in the cluster.
     *
     * Roles have since arrived, and they carry it exactly as this said they
     * would — but authority now comes through a Role and no other way
     * (ADR-0025), so the grants below decide nothing: `User` refuses to read
     * the direct-grant pivot at all. This stays in place as history of what
     * ran on the installations that ran it, and is not the way any Permission
     * reaches the table any more (ADR-0026).
     *
     * Written against the tables rather than the models so it keeps doing the
     * same thing however the User model or the package trait evolve.
     */
    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $grants = DB::table('users')
            ->pluck('id')
            ->map(fn (int|string $userId): array => [
                'permission_id' => $permissionId,
                'model_type' => 'App\Models\User',
                'model_id' => $userId,
            ])
            ->all();

        DB::table('model_has_permissions')->insertOrIgnore($grants);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
