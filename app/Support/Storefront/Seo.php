<?php

namespace App\Support\Storefront;

use App\Support\Settings\StoreSettings;
use Illuminate\Http\Request;

/**
 * What one storefront response says about itself to search engines and to
 * whatever unfurls a shared link: title, description, canonical address and
 * share image.
 *
 * Every response carries one as the `seo` prop. The middleware shares the
 * store's own as the default; a page with something better to say passes its
 * own `seo` prop, which replaces the shared one. The root template draws it
 * server-side so a crawler that runs no script reads it, and the layout's
 * `<Head>` draws the same keys so a client-side visit replaces them.
 */
final readonly class Seo
{
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $siteName,
        public ?string $image = null,
        public string $type = 'website',
    ) {}

    /**
     * The store's own: its name and tagline, its description, its default
     * share image, at the address asked for less any query string.
     */
    public static function forStore(StoreSettings $store, Request $request): self
    {
        return new self(
            title: filled($store->tagline) ? "{$store->name} — {$store->tagline}" : $store->name,
            description: $store->description,
            canonical: $request->url(),
            siteName: $store->name,
            image: $store->shareImageUrl(),
        );
    }

    /**
     * @return array{title: string, description: string, canonical: string, image: ?string, type: string, siteName: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'image' => $this->image,
            'type' => $this->type,
            'siteName' => $this->siteName,
        ];
    }
}
