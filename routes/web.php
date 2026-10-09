<?php

use App\Enums\Courier;
use App\Enums\PaymentMethod;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SigningInController;
use App\Http\Controllers\SitemapController;
use App\Models\Page;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
| The `web` group: session, cookies, PreventRequestForgery. Nothing here is
| ever cached.
*/

/*
| The storefront: Inertia pages inside the shared header and footer. Home,
| the System Page, answers at the bare domain; every other Page answers at
| its slug, registered last of all below.
*/

Route::get('/', [PageController::class, 'home'])->name('home');

/*
| The catalogue: a Product and a Category at their slugs, and search — the
| page, and the live dropdown's suggestions, which are throttled because
| they are asked for as the buyer types.
*/

Route::get('/tea/{slug}', [ProductController::class, 'show'])
    ->where('slug', Page::SLUG)
    ->name('product');

Route::get('/category/{slug}', [CategoryController::class, 'show'])
    ->where('slug', Page::SLUG)
    ->name('category');

Route::get('/search', [SearchController::class, 'index'])->name('search');

Route::get('/search/suggestions', [SearchController::class, 'suggestions'])
    ->middleware('throttle:search')
    ->name('search.suggestions');

/*
| What search engines read: every public address, and where they may go.
*/

Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');

Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

/*
| The Checkout: one fixed step for the whole store, and the Capture it posts
| to — an unauthenticated write on paid traffic, and so throttled.
*/

Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout');

Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:checkout')
    ->name('checkout.store');

/*
| Signing in, for My Orders: a One-Time Code texted to a number, the code
| traded for being signed in, and signing out. Each text is one the store
| pays for and each code is four digits, so both are throttled by address
| as well as by number.
*/

Route::post('/sign-in/code', [SigningInController::class, 'code'])
    ->middleware('throttle:one-time-code')
    ->name('sign-in.code');

Route::post('/sign-in', [SigningInController::class, 'signIn'])
    ->middleware('throttle:sign-in')
    ->name('sign-in');

Route::post('/sign-out', [SigningInController::class, 'signOut'])->name('sign-out');

/*
| The Order Complete Page, addressed by order number.
*/

Route::get('/order/{order:order_number}', [CheckoutController::class, 'orderComplete'])
    ->name('checkout.order-complete');

/*
| The Retry Page: an Unpaid Order's summary and the store's methods again.
| The choice is a write from a public page, and so is throttled.
*/

Route::get('/order/{order:order_number}/retry', [CheckoutController::class, 'retry'])
    ->name('checkout.retry');

Route::post('/order/{order:order_number}/retry', [CheckoutController::class, 'choose'])
    ->middleware('throttle:checkout')
    ->name('checkout.retry.choose');

/*
| The Notification: where a gateway tells the system on its own, one address
| per online gateway. POST only, since nothing but a gateway's server ever
| calls it; outside request-forgery protection because that call carries no
| session and no token; throttled, because it is unauthenticated and triggers
| a call to the gateway. Registered before the Processing Page so that
| `notify` is never read as an invoice reference.
*/

Route::post('/pay/{method}/notify', [PaymentController::class, 'notify'])
    ->whereIn('method', PaymentMethod::names(PaymentMethod::online()))
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:payment-notification')
    ->name('payment.notify');

/*
| The Processing Page and its Verification, one address per online gateway.
|
| A gateway sends the Customer back with a cross-site POST carrying no session
| and no token, so both sit outside request-forgery protection; they take GET
| as well, so a refresh, or a client without script following the meta
| refresh, lands on the same decision. Addressed by our invoice reference in
| the path and by nothing the gateway put in its parameters.
*/

Route::match(['get', 'post'], '/pay/{method}/{payment:invoice_reference}', [PaymentController::class, 'processing'])
    ->whereIn('method', PaymentMethod::names(PaymentMethod::online()))
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('payment.processing');

Route::match(['get', 'post'], '/pay/{method}/{payment:invoice_reference}/verify', [PaymentController::class, 'verify'])
    ->whereIn('method', PaymentMethod::names(PaymentMethod::online()))
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('payment.verify');

/*
| The Announcement: where a Courier tells the system on its own that
| something happened to a parcel, one address per Courier. POST only, since
| nothing but a Courier's server ever calls it; outside request-forgery
| protection because that call carries no session and no token; throttled,
| because it is unauthenticated and triggers a call back to the Courier.
|
| Registered before anything else under `/courier/{courier}/`, so that
| `notify` can never be read as a reference by a later route taking one in
| that position.
|
| Constrained to every name the enum holds and not only to the couriers that
| announce: which of them could ever post here is a driver's answer, and
| asking it would read this installation's Settings while a route file is
| being read. A Courier that announces nothing reads nothing off the request
| and is heard all the same (ADR-0019).
*/

Route::post('/courier/{courier}/notify', [CourierController::class, 'notify'])
    ->whereIn('courier', Courier::names())
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:courier-announcement')
    ->name('courier.notify');

/*
| A Page at its slug. Last, so that every fixed route above answers first;
| the slugs those routes start with are reserved and no Page can hold one.
*/

Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', Page::SLUG)
    ->name('page');
