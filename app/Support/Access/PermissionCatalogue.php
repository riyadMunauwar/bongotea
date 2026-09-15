<?php

namespace App\Support\Access;

use App\Enums\Permission;
use Spatie\Permission\Models\Permission as PermissionRow;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writing the catalogue into the table it is stored in.
 *
 * The catalogue is the enum and the table is storage (ADR-0026), so this
 * class only ever moves in one direction: the enum says what exists, and a
 * row is made for a case that has none and dropped for a row no case names.
 * Nothing here ever reads the table to find out what a Permission is.
 *
 * Nothing is incorrect without it having run — `ensure()` writes the row a
 * grant needs, and an Owner bypasses the map entirely — so this is for
 * pre-creating rows on a fresh installation, pruning what a release removed,
 * and repairing an installation by hand.
 */
class PermissionCatalogue
{
    /**
     * The guard every Permission and Role in this application is held under.
     * There is one guard: a User signs into the panel and nothing else.
     */
    public const GUARD = 'web';

    /**
     * Make the table say what the enum says.
     *
     * @return array{created: list<string>, removed: list<string>}
     */
    public function sync(): array
    {
        $declared = Permission::names();

        $stored = PermissionRow::query()
            ->where('guard_name', self::GUARD)
            ->pluck('name')
            ->all();

        $created = array_values(array_diff($declared, $stored));
        $removed = array_values(array_diff($stored, $declared));

        foreach ($created as $name) {
            PermissionRow::create(['name' => $name, 'guard_name' => self::GUARD]);
        }

        if ($removed !== []) {
            /**
             * Deleting the row takes the Permission off every Role holding it,
             * which is the point: a row no case names gates nothing, and left
             * in place it draws on the Roles screen as a tick that grants
             * nothing — a lie about what is being handed over.
             */
            PermissionRow::query()
                ->where('guard_name', self::GUARD)
                ->whereIn('name', $removed)
                ->get()
                ->each
                ->delete();
        }

        $this->forget();

        return ['created' => $created, 'removed' => $removed];
    }

    /**
     * The row for one case, made if the installation has not got it yet.
     *
     * This is how a Permission reaches the table outside the sync — granting
     * one to a Role must never depend on a command somebody remembered to run.
     */
    public function ensure(Permission $permission): PermissionRow
    {
        /** @var PermissionRow $row */
        $row = PermissionRow::findOrCreate($permission->value, self::GUARD);

        return $row;
    }

    /**
     * Drop the package's cached Permission map.
     *
     * Every write that touches Roles or their Permissions ends here, or a
     * revoked Permission keeps working until the cache expires — in exactly
     * the situation where that matters most (ADR-0025).
     */
    public function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
