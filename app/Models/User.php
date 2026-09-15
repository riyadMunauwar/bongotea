<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The floor for the panel is holding a Role — any Role.
     *
     * What a User may do once they are through it is decided Permission by
     * Permission, and a User holding no Role may do nothing at all, so there
     * would be nothing to show them: holding no Role is how somebody is
     * suspended, and an empty admin reads as a broken one. `AuthenticateAdmin`
     * is where they are told as much rather than left to guess.
     *
     * Implemented without this class asking which Roles, because there is no
     * number of Roles that is "enough" — the union of what they carry is the
     * answer to every finer question, and every one of those is asked through
     * `can()`, which an Owner passes by bypass rather than by holding rows
     * (ADR-0024).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles->isNotEmpty();
    }

    /**
     * A Permission granted straight to a User decides nothing (ADR-0025).
     *
     * Authority is the union of the Roles somebody holds, and the property
     * that buys is that what they may do has exactly one explanation: taking
     * a Role away takes the power with it, rather than quietly leaving it
     * behind under a grant the Roles screen cannot show. Nothing here writes
     * that pivot — but refusing to *read* it is what makes the guarantee true
     * rather than a habit, and it is what renders inert the rows the
     * `manage settings` migration left on an installation that predates
     * Roles, which is exactly the state that migration's own docblock says
     * roles would take over.
     *
     * @param  string|int|\BackedEnum|Permission  $permission
     */
    public function hasDirectPermission($permission): bool
    {
        return false;
    }

    /**
     * Whether this User may do everything.
     *
     * Asked only where the Owner is the subject — the console command, the
     * People screens, the guards that keep an installation from being left
     * without one. Never ask it in place of `can()`: a gate that asks this
     * has invented a second place where what an Owner may do is decided.
     */
    public function isOwner(): bool
    {
        return $this->hasRole(Role::OWNER);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
