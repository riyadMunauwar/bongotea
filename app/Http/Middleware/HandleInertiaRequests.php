<?php

namespace App\Http\Middleware;

use App\Support\Money;
use App\Support\Settings\StoreSettings;
use App\Support\Storefront\Seo;
use App\Support\Storefront\StorefrontShell;
use App\Support\Tracking\TrackingSnippet;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The storefront's Inertia middleware: the root template, and the props
 * every storefront page shares — the header, drawer and footer, what the
 * browser's Cart is priced from, a default
 * `seo` a page may replace with its own, and how many minor units make a
 * taka, since every price is sent in minor units, and where the Pixel and
 * the beacon are reached.
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
            'pricing' => fn (): array => $shell()->pricing(),
            'seo' => fn (): array => Seo::forStore(app(StoreSettings::class), $request)->toArray(),
            'minorUnitsPerTaka' => fn (): int => Money::factor(),
            'tracking' => fn (): array => [
                'beacon' => app(TrackingSnippet::class)->beacon(),
                'moments' => TrackingSnippet::MOMENTS,
            ],
        ];
    }
}
