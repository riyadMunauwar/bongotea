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
| On a warm cache these paths are answered from disk — by the webserver where
| it is configured to, and otherwise by the fallback reader — and none of this
| code runs. It executes only to fill the cache.
|
| This is the application's catch-all, so it must be registered last.
|
*/

/*
| The Site Root.
|
| Here rather than in `routes/web.php` for the reason written above: in the
| `web` group it would carry StartSession and PreventRequestForgery, so the
| response would ship a Set-Cookie and an XSRF-TOKEN cookie and stop being
| byte-identical to what was written to disk — and the Site Root is cached like
| any other address now (ADR-0023).
|
| It cannot be shadowed by, and cannot shadow, the catch-all below: a slug is
| one or more characters by construction, so `/{slug}` never matches the bare
| root.
*/

Route::get('/', [LandingPageController::class, 'front'])->name('landing.front');

Route::get('/{slug}', [LandingPageController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('landing.show');
