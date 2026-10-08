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
             * The tracking beacon runs with no middleware whatsoever — no
             * StartSession, no cookies, no PreventRequestForgery — because it
             * sits on the path of every page load and exists to be cheap.
             */
            Route::group([], base_path('routes/tracking.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * There is no standalone `login` route: the admin is the Filament
         * panel, so that is where an unauthenticated request to an admin-only
         * route belongs.
         */
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));

        /**
         * preventRequestForgery(originOnly: true) is deliberately NOT set.
         *
         * The Sec-Fetch-Site fast path is unconditional in Laravel 13 —
         * PreventRequestForgery::hasValidOrigin() returns true on 'same-origin'
         * whatever originOnly is — so a same-origin POST succeeds with no
         * token.
         *
         * Setting originOnly: true would only remove the fallback: it makes a
         * missing Sec-Fetch-Site throw OriginMismatchException *before*
         * tokensMatch() is reached. The default keeps the origin header as the
         * fast path and the token as a fallback for any client that omits it,
         * which some Facebook and Instagram in-app webviews do.
         */
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
