<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\AuthenticateAdmin;
use App\Support\Settings\GeneralSettings;
use BladeUI\Icons\Factory as IconFactory;
use Filament\FontProviders\BunnyFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
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
    public function boot(): void
    {
        /**
         * `resources/svg/` as the `giopages` icon set, which is where an icon
         * this application draws itself lives. Blade Icons ships with
         * Filament, so this is a registration and not a dependency.
         *
         * It exists for one glyph: the funnel Pages is drawn with. A Merchant
         * arrives here from CartFlows and reads a funnel as "the surface that
         * makes money", which is what the sidebar wants to say — but
         * `Heroicon::Funnel` is Filament's own filter-action icon, and
         * `PagesTable` has filters, so shipping that enum would put the same
         * glyph in two meanings on the screen the item opens onto. Hence one
         * of ours, drawn to Heroicons' outline spec so it sits among the four
         * beside it as a peer rather than an accent.
         *
         * The metaphor stops at the icon. `CONTEXT.md` keeps "funnel step" on
         * Page's avoid-list on purpose — a CartFlows funnel is multi-step by
         * construction and a Page is deliberately not — and nothing here is
         * an argument to start writing the word in code.
         */
        app(IconFactory::class)->add('giopages', [
            'path' => resource_path('svg'),
            'prefix' => 'giopages',
        ]);

        /**
         * Every date-time the panel draws — a capture time on the Orders list,
         * a Payment's verified-at — is shown in the Merchant's timezone, read
         * from the General group. A closure, so the group is resolved the first
         * time a date is drawn and never for a request that draws none.
         * Storage stays UTC; only the display moves.
         */
        FilamentTimezone::set(fn (): string => app(GeneralSettings::class)->timezone);
    }

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
            /**
             * Every User gets a profile page — their own name and password,
             * and nothing else (the page is where the reasoning sits). Drawn
             * inside the panel rather than as a standalone simple page, so a
             * User reaches it from the account menu without leaving the shell.
             */
            ->profile(EditProfile::class, isSimple: false)
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
             * and the class is where the reasoning about it sits — including
             * what the widget discovery below it puts on that screen.
             */
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            /**
             * The Settings cluster, and with it the pages inside: each is
             * discovered under its cluster's folder, which is what makes the
             * set of Settings pages the set of files there.
             */
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
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
                AuthenticateAdmin::class,
            ]);
    }
}
