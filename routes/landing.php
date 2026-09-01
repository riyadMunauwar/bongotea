<?php

use App\Http\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landing page routes
|--------------------------------------------------------------------------
|
| Registered with no middleware group at all (see bootstrap/app.php) — in
| particular no StartSession and no PreventRequestForgery. That is what makes
| the response cacheable: with no session there is no Set-Cookie and no
| XSRF-TOKEN cookie, so the bytes written to the static cache on a miss are
| byte-identical to what a live render returns. A GET is never blocked by CSRF
| anyway.
|
| On a warm cache nginx answers these paths from disk and none of this code
| runs. It executes only to fill the cache.
|
| This is the application's catch-all, so it must be registered last.
|
*/

Route::get('/{slug}', [LandingPageController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('landing.show');
