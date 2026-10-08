<?php

namespace App\Http\Middleware;

use App\Support\Settings\StoreSettings;
use App\Support\Storefront\Seo;
use App\Support\Storefront\StorefrontShell;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The storefront's Inertia middleware: the root template, and the props
 * every storefront page shares — the header, drawer and footer, and a
 * default `seo` a page may replace with its own.
 *
 * Every shared prop is a closure, so a request this middleware passes that
 * renders no Inertia page — a payment callback, the Order Complete Page —
 * reads no Setting and runs no query.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $shell = fn (): StorefrontShell => app(StorefrontShell::class);

        return [
            ...parent::share($request),
            'store' => fn (): array => $shell()->store(),
            'announcement' => fn (): ?array => $shell()->announcement(),
            'menus' => fn (): array => $shell()->menus($request->getHost()),
            'paymentMethods' => fn (): array => $shell()->paymentMethods(),
            'links' => fn (): array => $shell()->links(),
            'seo' => fn (): array => Seo::forStore(app(StoreSettings::class), $request)->toArray(),
        ];
    }
}
