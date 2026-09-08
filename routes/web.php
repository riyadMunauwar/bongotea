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

/*
| The Retry Page: an Unpaid Order's summary and its Page's methods again.
| Three segments deep, so a slug cannot shadow it either. The choice is a
| write from a public page, and so is throttled like a Capture.
*/

Route::get('/order/{order:order_number}/retry', [CheckoutController::class, 'retry'])
    ->name('checkout.retry');

Route::post('/order/{order:order_number}/retry', [CheckoutController::class, 'choose'])
    ->middleware('throttle:checkout')
    ->name('checkout.retry.choose');
