<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Access\UserGuard;

/**
 * The two guards that keep an installation from bricking itself.
 *
 * The last Owner cannot be demoted, suspended or filed away, and nobody can
 * take away their own last Role. Both are pure decisions about who may lose
 * what, tested here apart from the screen that enforces them.
 */
beforeEach(function (): void {
    $this->guard = app(UserGuard::class);
});

it('refuses to demote the last Owner, and says why', function () {
    $owner = User::factory()->owner()->create();

    $refusal = $this->guard->refusalForRoles($owner, $owner, []);

    expect($refusal)->toBe(UserGuard::LAST_OWNER);
});

it('lets the last Owner keep the Owner Role among others', function () {
    $owner = User::factory()->owner()->create();
    $clerk = Role::findOrCreate('Orders Clerk');

    $refusal = $this->guard->refusalForRoles(
        $owner,
        $owner,
        [Role::owner()->getKey(), $clerk->getKey()],
    );

    expect($refusal)->toBeNull();
});

it('lets an Owner be demoted while another Owner stands', function () {
    $first = User::factory()->owner()->create();
    $second = User::factory()->owner()->create();
    $clerk = Role::findOrCreate('Orders Clerk');

    $refusal = $this->guard->refusalForRoles($first, $second, [$clerk->getKey()]);

    expect($refusal)->toBeNull();
});

it('refuses to take away your own last Role, and says why', function () {
    $owner = User::factory()->owner()->create();
    $clerk = Role::findOrCreate('Orders Clerk');
    $subject = User::factory()->create();
    $subject->syncRoles([$clerk]);

    // The actor is editing their own record down to nothing.
    $refusal = $this->guard->refusalForRoles($subject, $subject, []);

    expect($refusal)->toBe(UserGuard::OWN_LAST_ROLE);
});

it('lets you suspend somebody who is not you', function () {
    $owner = User::factory()->owner()->create();
    $other = User::factory()->create();

    $refusal = $this->guard->refusalForRoles($owner, $other, []);

    expect($refusal)->toBeNull();
});

it('refuses to file away the last Owner, and says why', function () {
    $owner = User::factory()->owner()->create();

    expect($this->guard->refusalForFilingAway($owner))->toBe(UserGuard::LAST_OWNER);
});

it('lets an Owner be filed away while another Owner stands', function () {
    User::factory()->owner()->create();
    $second = User::factory()->owner()->create();

    expect($this->guard->refusalForFilingAway($second))->toBeNull();
});

it('lets an ordinary User be filed away', function () {
    User::factory()->owner()->create();
    $other = User::factory()->create();

    expect($this->guard->refusalForFilingAway($other))->toBeNull();
});
