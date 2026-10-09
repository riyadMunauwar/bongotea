<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Support\Storefront\CatalogueProps;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use XMLWriter;

/**
 * What search engines read: the sitemap, listing every address a buyer can
 * reach — published Pages (Home at the bare domain), every Category, and
 * every published Product still in the catalogue — and robots.txt, which
 * points at it and keeps crawlers out of the admin, Orders and payments.
 * Both are worked out on each request, so neither can go stale.
 */
class SitemapController extends Controller
{
    /** Addresses no crawler has any business in: the admin, an Order's pages, payments, search results. */
    public const DISALLOWED = ['/admin', '/checkout', '/order/', '/pay/', '/search'];

    public function sitemap(): Response
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        Page::query()->published()->orderBy('id')->each(
            fn (Page $page) => $this->writeUrl($xml, url($page->path()), $page->updated_at),
        );

        Category::query()->orderBy('sort_order')->orderBy('id')->each(
            fn (Category $category) => $this->writeUrl($xml, url(CatalogueProps::categoryPath($category)), $category->updated_at),
        );

        Product::query()->published()->orderBy('id')->each(
            fn (Product $product) => $this->writeUrl($xml, url(CatalogueProps::productPath($product)), $product->updated_at),
        );

        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            ...array_map(fn (string $path): string => "Disallow: {$path}", self::DISALLOWED),
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function writeUrl(XMLWriter $xml, string $location, ?Carbon $updatedAt): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $location);

        if ($updatedAt !== null) {
            $xml->writeElement('lastmod', $updatedAt->toAtomString());
        }

        $xml->endElement();
    }
}
