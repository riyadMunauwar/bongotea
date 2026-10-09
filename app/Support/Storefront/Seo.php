<?php

namespace App\Support\Storefront;

use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use App\Support\Settings\StoreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     * A Page's own: what its SEO fields say, falling back to its title beside
     * the store's name, the store's description and the store's share image.
     * Home falls back to the store's own title, and its canonical address is
     * the bare domain whatever was asked for.
     */
    public static function forPage(Page $page, StoreSettings $store, Request $request): self
    {
        $default = self::forStore($store, $request);

        return new self(
            title: filled($page->seo_title) ? $page->seo_title : ($page->isHome() ? $default->title : "{$page->title} — {$store->name}"),
            description: filled($page->seo_description) ? $page->seo_description : $default->description,
            canonical: url($page->path()),
            siteName: $store->name,
            image: filled($page->seo_image) ? url(Storage::disk(PageForm::SEO_IMAGE_DISK)->url($page->seo_image)) : $default->image,
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
