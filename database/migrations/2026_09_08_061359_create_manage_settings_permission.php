<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The literal rather than `SettingsCluster::PERMISSION`, so this stays
     * runnable whatever the cluster is later renamed to. The two must agree.
     */
    private const PERMISSION = 'manage settings';

    /**
     * Bring in the one permission Settings is gated by, and hand it to every
     * User who is already here.
     *
     * Every User was an admin before this permission existed, so an upgrade
     * that created the gate without opening it for them would lock a Merchant
     * out of their own Settings. Granted directly rather than through a role:
     * roles are not in the product yet, and when they arrive they will carry
     * this same permission with no change here or in the cluster. A User made
     * after this runs gets nothing, which is the point of a gate.
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
