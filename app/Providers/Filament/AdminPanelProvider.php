<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use Filament\FontProviders\BunnyFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            /**
             * The login screen is the first thing anyone sees of the product,
             * so it is the panel's own rather than the framework's. The class
             * only switches off the wordmark Filament prints above the heading;
             * the mark that stands in for it is the render hook further down.
             */
            ->login(Login::class)
            ->colors([
                'primary' => Color::Blue,
                /**
                 * A pure neutral ramp, where Filament's default zinc carries a
                 * cool cast. The theme's surfaces are named as shades of this,
                 * so blue stays the only chromatic thing in the panel.
                 */
                'gray' => Color::Neutral,
            ])
            /**
             * Geist, from Bunny — the host `vite.config.js` already has the
             * public build pulling its face from. Noto Sans Bengali rides
             * along in the same request at the same four weights, because
             * Geist carries no `৳` and that symbol is on every price and Order
             * total in the panel. Loading the two together is what keeps the
             * symbol on the same weight as the digits beside it;
             * `typography.css` is where they are ordered into one stack.
             */
            ->font(
                'Geist',
                url: 'https://fonts.bunny.net/css?family=geist:400,500,600,700|noto-sans-bengali:400,500,600,700&display=swap',
                provider: BunnyFontProvider::class,
                /**
                 * Filament preloads nothing once a custom family is named, and
                 * the two faces below are the ones a cold load cannot afford to
                 * wait for: the body text, and the face `৳` comes from. Until
                 * the second arrives the symbol is drawn by whichever Bengali
                 * font the machine happens to ship — the defect this typeface
                 * work exists to remove, showing through for as long as the
                 * stylesheet takes to resolve. The other weights may swap in
                 * late; these two should not.
                 */
                preload: [
                    'https://fonts.bunny.net/geist/files/geist-latin-400-normal.woff2',
                    'https://fonts.bunny.net/noto-sans-bengali/files/noto-sans-bengali-bengali-400-normal.woff2',
                ],
            )
            /**
             * No topbar. It spent 4rem across every screen on a search field
             * and an avatar; Filament moves global search, database
             * notifications and the account menu into the sidebar on its own
             * once it is gone, so nothing is lost.
             */
            ->topbar(false)
            /**
             * The login card, down from Filament's 32rem. Two fields and a
             * button read as a wide empty box at that width, and it only got
             * wider once the density pass took the card's padding in.
             */
            ->simplePageMaxContentWidth(Width::Medium)
            ->sidebarWidth('14rem')
            ->collapsedSidebarWidth('3.5rem')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            /**
             * Discovery is what registers `Pages\Dashboard` on the panel root,
             * and the class is where the reasoning about it sits. No widget is
             * registered anywhere, which is what leaves that screen empty.
             */
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            /**
             * The branding, and specifically the mark.
             *
             * Filament renders its brand logo inside a container it hides the
             * moment the sidebar collapses, so branding placed there leaves the
             * rail's top-left empty — which reads as broken rather than
             * compact, in a state a User can sit in all day. The mark is
             * rendered ahead of that container instead, where nothing hides it,
             * and the wordmark is left to Filament's own brand name: the word
             * is the half that can afford to go with the width. `sidebar.css`
             * is where the rail gives the mark the slot the expand button
             * otherwise fills.
             */
            ->renderHook(
                PanelsRenderHook::SIDEBAR_LOGO_BEFORE,
                fn (): View => view('filament.admin.brand-mark'),
            )
            /**
             * And the same mark on the way in, above the login form.
             *
             * Filament offers no hook inside its simple header, so the mark is
             * rendered ahead of the whole of it — which is where it belongs
             * anyway, since the wordmark it stands in for is switched off in
             * `Login`. Left unscoped on purpose: every screen a User meets
             * before they are authenticated should be branded the same way,
             * and the login screen is only the first of them.
             */
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn (): View => view('filament.admin.brand-mark'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
