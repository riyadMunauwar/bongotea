<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament's own panel guard, with a sentence attached.
 *
 * A User holding no Role is refused everything, and the framework's refusal is
 * a bare 403 — which reads, to somebody who was told this morning that their
 * account was ready, as a broken installation rather than as an account
 * nobody has given a job to yet. The rule itself is not restated here — it is
 * `User::canAccessPanel()`, which is what the framework asks too — and all
 * this adds is the wording for the refusal that answer already earns. Where a
 * User may pass, nothing has been decided here and the parent decides the
 * rest, guests included.
 */
class AuthenticateAdmin extends Authenticate
{
    /** What a User with no Role is told, in place of an empty admin. */
    public const REFUSAL = 'Your account has not been given a Role yet, so there is nothing here for you to open. Ask the Owner of this installation to give you one.';

    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        /** @var Model|null $user */
        $user = Filament::auth()->user();

        abort_if(
            $user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()),
            403,
            self::REFUSAL,
        );

        parent::authenticate($request, $guards);
    }
}
