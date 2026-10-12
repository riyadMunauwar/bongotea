<?php

namespace App\Support\Tracking;

use App\Enums\PlaceKind;
use App\Enums\SystemPage;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The place of the store a Visit was a load of: a Page, a Category or a
 * Product by the record itself, or Search or the Checkout as one place each.
 *
 * Worked out from the address the beacon was sent from, by asking the router
 * which storefront route answers it and then looking up the record that route
 * would draw — so a renamed Slug is the same place under its new address, and
 * an address the store would answer with a 404 is no place at all.
 */
final readonly class Place
{
    /**
     * The id a place with no record behind it is stored under.
     */
    public const int NO_RECORD = 0;

    private function __construct(
        public PlaceKind $kind,
        public int $id = self::NO_RECORD,
    ) {}

    /**
     * The place an address names, or null where it names none: an address on
     * no storefront route, a Slug nothing holds, or a draft.
     *
     * Only the path is read. The host has already been vouched for by the
     * beacon's same-origin check, and a query string (a search term, a
     * campaign tag) is not part of where somebody was.
     */
    public static function at(?string $address): ?self
    {
        if (! is_string($address) || $address === '') {
            return null;
        }

        /** The bare domain carries no path at all, and is Home. */
        $path = parse_url($address, PHP_URL_PATH) ?? '/';

        if (! is_string($path)) {
            return null;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path));
        } catch (HttpException) {
            return null;
        }

        $slug = (string) $route->parameter('slug');

        return match ($route->getName()) {
            'home' => self::record(PlaceKind::Page, Page::query()->system(SystemPage::Home)->published()->value('id')),
            'page' => self::record(PlaceKind::Page, Page::query()->published()->where('slug', $slug)->value('id')),
            'category' => self::record(PlaceKind::Category, Category::query()->where('slug', $slug)->value('id')),
            'product' => self::record(PlaceKind::Product, Product::query()->published()->where('slug', $slug)->value('id')),
            'search' => new self(PlaceKind::Search),
            'checkout' => new self(PlaceKind::Checkout),
            default => null,
        };
    }

    private static function record(PlaceKind $kind, mixed $id): ?self
    {
        return $id === null ? null : new self($kind, (int) $id);
    }
}
