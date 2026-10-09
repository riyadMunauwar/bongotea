<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Customers\CustomerGuard;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\SettingsGroup;
use App\Support\Settings\TrackingSettings;
use App\Support\Storefront\Catalogue;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    /** Search suggestions one address may ask for in a minute before it is refused. */
    private const SUGGESTIONS_PER_MINUTE = 120;

    /** One-Time Codes one address may ask for in an hour, whatever the numbers. */
    private const CODES_PER_HOUR = 20;

    /** Codes one address may try in a minute before it is refused. */
    private const SIGN_INS_PER_MINUTE = 10;

    /** Enquiries one address may send in an hour, whatever the numbers. */
    private const ENQUIRIES_PER_HOUR = 20;

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

        /** The catalogue every live Block on a Page reads, queried once per request. */
        $this->app->scoped(Catalogue::class);
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

        /**
         * The search dropdown asks once per pause in typing, so a buyer
         * never comes near this; a script hammering the catalogue query does.
         */
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(
            self::SUGGESTIONS_PER_MINUTE,
        )->by($request->ip()));

        /**
         * Every One-Time Code is a text the store pays for. A number is held
         * to three an hour by `OneTimeCodes`; this holds one address to a
         * handful of numbers, set above what a carrier's shared address
         * plausibly asks for, as the checkout's is.
         */
        RateLimiter::for('one-time-code', fn (Request $request): Limit => Limit::perHour(
            self::CODES_PER_HOUR,
        )->by($request->ip()));

        /**
         * A code is four digits. Each one is thrown away after five wrong
         * guesses; this keeps one address from guessing across many numbers.
         */
        RateLimiter::for('sign-in', fn (Request $request): Limit => Limit::perMinute(
            self::SIGN_INS_PER_MINUTE,
        )->by($request->ip()));

        /**
         * Every Enquiry texts the store's own numbers. A number is held to a
         * few an hour by `EnquiryController`; this holds one address to a
         * handful of numbers, set above what a carrier's shared address
         * plausibly sends, as the checkout's is.
         */
        RateLimiter::for('enquiry', fn (Request $request): Limit => Limit::perHour(
            self::ENQUIRIES_PER_HOUR,
        )->by($request->ip()));

        /**
         * A Customer Signed in with a One-Time Code, beside the admin's Users
         * and never mixed with them. Long-lived, so it reads each request
         * afresh rather than the one it was made for.
         */
        Auth::extend('customer', function (Application $app): CustomerGuard {
            $guard = new CustomerGuard($app->make('request'));

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
