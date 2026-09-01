<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /**
             * Public landing pages run with no middleware whatsoever — in
             * particular no StartSession and no PreventRequestForgery.
             *
             * That is what makes the response cacheable: with no session there
             * is no Set-Cookie and no XSRF-TOKEN cookie, so the bytes written
             * to the static cache on a miss are byte-identical to what a live
             * render would return. A GET is never blocked by CSRF anyway
             * (PreventRequestForgery::isReading() passes GET/HEAD/OPTIONS), so
             * nothing is given up here.
             *
             * Registered after the web routes because `/{slug}` is a catch-all.
             */
            Route::group([], base_path('routes/landing.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * There is no standalone `login` route: the admin is the Filament
         * panel, so that is where an unauthenticated request to an admin-only
         * route (the page preview) belongs.
         */
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));

        /**
         * preventRequestForgery(originOnly: true) is deliberately NOT set.
         *
         * The Sec-Fetch-Site fast path is unconditional in Laravel 13 —
         * PreventRequestForgery::hasValidOrigin() returns true on 'same-origin'
         * whatever originOnly is — so a cookie-free static checkout page
         * already POSTs successfully with no token.
         *
         * Setting originOnly: true would only remove the fallback: it makes a
         * missing Sec-Fetch-Site throw OriginMismatchException *before*
         * tokensMatch() is reached. The default keeps the origin header as the
         * fast path and the token as a fallback for any client that omits it.
         * Facebook and Instagram in-app webviews carry the checkout traffic, so
         * the fallback earns its cost.
         */
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
