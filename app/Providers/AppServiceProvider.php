<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\SettingsGroup;
use App\Support\Settings\TrackingSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Notifications one address may post in a minute before it is refused. */
    private const NOTIFICATIONS_PER_MINUTE = 60;

    /** Announcements one address may post in a minute before it is refused. */
    private const ANNOUNCEMENTS_PER_MINUTE = 60;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        /**
         * A Settings group is one instance per request, built from its row the
         * first time something asks for it and never before — so a request
         * that reads no Setting pays no query for one (ADR-0012). Every
         * subclass is bound here, the first time it is resolved, so adding a
         * group is one class plus one page and touches nothing else.
         */
        $this->app->beforeResolving(SettingsGroup::class, function (string $group, array $parameters, Application $app): void {
            if (! $app->bound($group)) {
                $app->singleton($group, fn (): SettingsGroup => $group::load());
            }
        });

        /**
         * Every admin record is addressed by its integer id, so anything else
         * in a `{record}` is a missing page. Said here rather than left to the
         * database, because PostgreSQL refuses to compare a bigint with a word
         * like `create` and the 404 becomes a 500. In `register()` because a
         * pattern only reaches routes added after it, and Filament adds the
         * panel's while its own provider boots, before this one does.
         */
        Route::pattern('record', '[0-9]+');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * The Owner may do everything, and does it by standing outside the
         * Permissions rather than holding every one of them (ADR-0024).
         *
         * Answering before the Permission map is consulted is what makes
         * "everything" true in advance: a Permission shipped in a future
         * release is already theirs, so an upgrade can never hide a new screen
         * from the one person who could grant it. Null rather than false for
         * anyone else, which is how a `before` hook declines to decide and
         * lets the map answer.
         *
         * Total on purpose, including over policies written years from now: an
         * Owner who could be refused something is not an Owner, and a second
         * place deciding what they may do is the drift this exists to prevent.
         */
        Gate::before(fn (User $user): ?bool => $user->isOwner() ? true : null);

        /**
         * The checkout's writes are unauthenticated, and they sit on paid
         * traffic.
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
         * An Announcement is an unauthenticated POST that costs a call back
         * to the Courier, so it is throttled per address like a Notification.
         * Set to what a Courier's own servers plausibly send an installation
         * in a minute, with room: a Courier that is refused retries, and the
         * sweep catches what never arrives, so nothing is lost but time.
         */
        RateLimiter::for('courier-announcement', fn (Request $request): Limit => Limit::perMinute(
            self::ANNOUNCEMENTS_PER_MINUTE,
        )->by($request->ip()));

        /**
         * The beacon is an unauthenticated write on the path of every ad
         * click, so it is throttled per address like the checkout beside it.
         * Keyed on IP alone for the same reason and with the same trade-off
         * written down at the Setting it reads.
         */
        RateLimiter::for('tracking', fn (Request $request): Limit => Limit::perMinute(
            $this->app->make(TrackingSettings::class)->beaconRateLimit,
        )->by($request->ip()));
    }
}
