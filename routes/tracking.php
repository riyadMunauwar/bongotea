<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tracking routes
|--------------------------------------------------------------------------
|
| Registered with no middleware group at all (see bootstrap/app.php) — no
| StartSession, no cookies, no PreventRequestForgery — for the same reason
| the landing routes are: this is what a Page served as a static file by the
| webserver reaches back to, and a session here would cost a request that
| exists to be cheap the only thing it has.
|
| Forgery is answered instead by `Sec-Fetch-Site: same-origin`, the same
| signal the Checkout POST already trusts, and abuse by the rate limit. What
| is deliberately absent is a signed token: one baked into cached HTML either
| never expires — and is trivially replayable, buying nothing — or expires
| and silently kills tracking on every Page the cache has not refreshed.
|
| Two path segments, like every other non-landing route in the system,
| precisely so the one-segment landing catch-all cannot shadow it.
|
*/

Route::post('/tracking/report', [TrackingController::class, 'report'])
    ->middleware('throttle:tracking')
    ->name('tracking.report');
