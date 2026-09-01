<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

/*
| The `web` group: session, cookies, PreventRequestForgery. Everything here
| boots the framework by design, and nothing here is ever cached.
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pages/{page}/preview', [LandingPageController::class, 'preview'])
    ->middleware('auth')
    ->name('pages.preview');

/*
| Checkout.
|
| Deliberately in this group rather than alongside the landing routes: Capture
| is a write, it happens once per visitor, and it wants the session and the
| request-forgery protection that would cost a landing page its cacheability.
|
| None of these can be shadowed by a Page's slug: the landing catch-all only
| answers GET, and the two GETs here are both two segments deep while a slug is
| always exactly one.
*/

Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:checkout')
    ->name('checkout.store');

Route::get('/checkout/token', [CheckoutController::class, 'token'])
    ->middleware('throttle:checkout')
    ->name('checkout.token');

Route::get('/order/{order:order_number}', [CheckoutController::class, 'confirmation'])
    ->name('checkout.confirmation');
