<?php

namespace App\Support\Access;

use App\Models\Role;
use App\Models\User;

/**
 * The two guards that keep an installation from bricking itself.
 *
 * An installation always has at least one Owner and cannot be left without
 * one, and nobody should be able to lock themselves out by accident. Both are
 * decisions about who may lose what — kept here, apart from the People screen
 * that enforces them, so the rule has one wording and one home rather than
 * being re-derived at the form save and again at the filing-away action.
 *
 * Neither guard is a substitute for the panel door. A suspended or filed-away
 * User is refused entry by `User::canAccessPanel()` and the soft-delete scope;
 * these guards decide only whether the Merchant is *allowed to put* a User
 * into one of those states.
 */
class UserGuard
{
    /**
     * Refused when the change would leave the installation with no Owner.
     * The one demotion, suspension or filing-away that is never allowed.
     */
    public const LAST_OWNER = 'This is the last Owner of the installation, which must always have one. Give the Owner Role to somebody else before you demote, suspend or file away this User.';

    /** Refused when the actor would strip the last Role off their own account. */
    public const OWN_LAST_ROLE = 'You cannot take away your own last Role: it would lock you out of the installation. Ask another Owner to change your Roles instead.';

    /**
     * Whether a proposed set of Roles for a User is refused, and why.
     *
     * The subject's Roles are about to become exactly `$roleIds`. Two things
     * are refused: demoting the last Owner (the set no longer carries the
     * Owner Role while nobody else holds it), and an actor emptying the Roles
     * on their own account. The last-Owner reason is checked first, because it
     * is the one that would leave the whole installation unreachable.
     *
     * @param  list<int|string>  $roleIds  the Role ids the subject would hold after the save
     */
    public function refusalForRoles(User $actor, User $subject, array $roleIds): ?string
    {
        $keepsOwner = in_array((int) Role::owner()->getKey(), array_map('intval', $roleIds), true);

        if ($subject->isLastOwner() && ! $keepsOwner) {
            return self::LAST_OWNER;
        }

        if ($actor->is($subject) && $roleIds === []) {
            return self::OWN_LAST_ROLE;
        }

        return null;
    }

    /**
     * Whether filing a User away is refused, and why.
     *
     * Only the last Owner is protected: filing anybody else away is a soft
     * delete another Owner can undo, and a filed-away Owner with a fellow
     * Owner standing leaves the installation reachable.
     */
    public function refusalForFilingAway(User $subject): ?string
    {
        return $subject->isLastOwner() ? self::LAST_OWNER : null;
    }
}
