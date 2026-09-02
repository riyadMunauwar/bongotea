<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

/**
 * The login screen.
 *
 * Filament's simple header prints the brand name above the page's heading, so
 * the screen arrives stacking "GioPages" over "Sign in": two lines of bold
 * text, at nearly the same weight, saying different things. The mark is
 * rendered above them instead — `AdminPanelProvider` holds the render hook —
 * and the wordmark gives way to it. A mark is the half of the branding that
 * does not have to be read, and "Sign in" is the only instruction the screen
 * owes anyone. The name itself is still announced, from the document title.
 */
class Login extends BaseLogin
{
    public function hasLogo(): bool
    {
        return false;
    }
}
