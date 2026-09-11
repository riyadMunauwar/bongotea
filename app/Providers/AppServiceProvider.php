<?php

namespace App\Providers;

use App\Shortcodes\Definitions\CheckoutShortcode;
use App\Shortcodes\ShortcodeRegistry;
use App\Support\PageCache\StaticPageCache;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\SettingsGroup;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Notifications one address may post in a minute before it is refused. */
    private const NOTIFICATIONS_PER_MINUTE = 60;

    /**
     * Moments one address may report in a minute before it is refused.
     *
     * Set well above what a real visitor produces — one beacon per page load
     * — because the addresses this counts are shared: a carrier's NAT puts
     * thousands of genuine visitors behind one, and this market browses from
     * mobile almost entirely. Being refused costs the redundancy and not the
     * event, since the browser's own Pixel has already fired, which is what
     * makes a generous limit the cheap side to be wrong on.
     *
     * A constant like the Notification's, not a Setting like the Capture's:
     * the tracking settings group that is to hold this arrives with the
     * Merchant's own event switches, and a Setting with no screen to set it
     * on would be a column nobody can reach.
     */
    private const MOMENTS_PER_MINUTE = 300;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StaticPageCache::class, fn ($app) => new StaticPageCache(
            $app->make(Filesystem::class),
            public_path('page-cache'),
        ));

        /**
         * A Settings group is one instance per request, built from its row the
         * first time something asks for it and never before — so a public
         * Page that reads no Setting pays no query for one (ADR-0012). Every
         * subclass is bound here, the first time it is resolved, so adding a
         * group is one class plus one page and touches nothing else.
         */
        $this->app->beforeResolving(SettingsGroup::class, function (string $group, array $parameters, Application $app): void {
            if (! $app->bound($group)) {
                $app->singleton($group, fn (): SettingsGroup => $group::load());
            }
        });

        /**
         * v1 registers exactly one shortcode. The registry is also the boundary
         * of the syntax we claim over a merchant's document: the parser matches
         * these names and nothing else.
         */
        $this->app->singleton(ShortcodeRegistry::class, function ($app): ShortcodeRegistry {
            $registry = new ShortcodeRegistry;

            $registry->register($app->make(CheckoutShortcode::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * The Capture endpoint is the one unauthenticated write in the system,
         * and it sits on paid traffic.
         *
         * Keyed on IP alone, which is worth naming as a trade-off rather than
         * leaving implicit: mobile carriers put thousands of genuine buyers
         * behind a single NAT address, so this is set well above what a shared
         * address plausibly produces instead of tuned tight. Blocking real
         * orders costs more than the abuse a tighter limit would stop.
         */
        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(
            $this->app->make(GeneralSettings::class)->checkoutRateLimit,
        )->by($request->ip()));

        /**
         * A Notification is an unauthenticated POST that costs a call to the
         * gateway, so it is throttled per address. Set to what a gateway's
         * own servers plausibly send an installation in a minute, with room:
         * a gateway that is refused retries, so nothing is lost but time.
         */
        RateLimiter::for('payment-notification', fn (Request $request): Limit => Limit::perMinute(
            self::NOTIFICATIONS_PER_MINUTE,
        )->by($request->ip()));

        /**
         * The beacon is an unauthenticated write on the path of every ad
         * click, so it is throttled per address like the Capture beside it.
         * Keyed on IP alone for the same reason and with the same trade-off
         * written down at the constant.
         */
        RateLimiter::for('tracking', fn (Request $request): Limit => Limit::perMinute(
            self::MOMENTS_PER_MINUTE,
        )->by($request->ip()));
    }
}
