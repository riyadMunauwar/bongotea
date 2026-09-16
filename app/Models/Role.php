<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A named bundle of Permissions a Merchant gives to Users.
 *
 * The package's own row, subclassed for one reason: the Owner is a Role by
 * name, and that name is asked for by the gate hook, the console command that
 * grants it, the seeder and the factory — so it is written down once, here,
 * beside the table it names a row in.
 *
 * A Role is the *only* channel authority travels down (ADR-0025). Nothing in
 * this application writes to `model_has_permissions`, so what a User may do is
 * the union of their Roles and has exactly one explanation on the screen that
 * shows it.
 */
class Role extends SpatieRole
{
    /**
     * The Role that may do everything.
     *
     * It holds no Permission rows at all: a `Gate::before` hook answers true
     * for anyone carrying it, ahead of the Permission map (ADR-0024), so a
     * Permission shipped in a future release is already theirs rather than
     * something somebody has to remember to tick.
     */
    public const OWNER = 'Owner';

    /**
     * The Owner Role, made if this installation has not got one yet.
     *
     * Every route to an Owner goes through here — the console command, the
     * seeder, the factory — so there is never a second row differing by a
     * capital letter.
     */
    public static function owner(): self
    {
        /** @var self $role */
        $role = self::findOrCreate(self::OWNER);

        return $role;
    }

    /**
     * Whether this is the Owner Role.
     *
     * Read where the Owner must be treated apart from every other Role — the
     * Roles screen locks it against renaming, deletion and ticking — so that
     * "which Role is untouchable?" is answered by its name in one place rather
     * than compared against `Role::OWNER` at each site.
     */
    public function isOwner(): bool
    {
        return $this->name === self::OWNER;
    }
}
