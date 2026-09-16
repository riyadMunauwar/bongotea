<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;

/**
 * The profile page every User gets.
 *
 * Two things and no more: their own name and their own password. The name so
 * it reads correctly on the Stock Counts it appears on, and the password so
 * rotating it never depends on the Owner — a User is handed an initial
 * password out of band and changes it here themselves.
 *
 * The email is left off deliberately. It is what a User signs in with and the
 * Owner sets it when the account is made; the installation sends no mail, so
 * there is no address-change verification to run, and letting a User rename
 * their own login is not a door this screen needs to open.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }
}
