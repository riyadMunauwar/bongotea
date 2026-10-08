<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tracking routes
|--------------------------------------------------------------------------
|
| Registered with no middleware group at all (see bootstrap/app.php) — no
| StartSession, no cookies, no PreventRequestForgery — because it sits on the
| path of every page load, and a session here would cost a request that
| exists to be cheap the only thing it has.
|
| Forgery is answered instead by `Sec-Fetch-Site: same-origin`, and abuse by
| the rate limit.
|
*/

Route::post('/tracking/report', [TrackingController::class, 'report'])
    ->middleware('throttle:tracking')
    ->name('tracking.report');
