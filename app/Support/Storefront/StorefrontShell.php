<?php

namespace App\Support\Storefront;

use App\Enums\OrderKind;
use App\Enums\SystemPage;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Support\PhoneNumber;
use App\Support\Pricing\DeliveryBand;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\RetailPriceBook;
use App\Support\Settings\FooterSettings;
use App\Support\Settings\HeaderSettings;
use App\Support\Settings\StoreSettings;
use Illuminate\Support\Collection;

/**
 * Everything the storefront's header, drawer and footer draw, as the props
 * every storefront response shares.
 *
 * Copy is not here: interface wording lives in the `bn` translation set on
 * the client, and these props carry only what the Merchant sets. Numbers go
 * as typed and the client draws them in Bangla digits; the addresses a phone
 * number is dialled or messaged on are worked out here, through the dialling
 * rules in General Settings.
 */
class StorefrontShell
{
    /**
     * Where the header's fixed buttons lead when their System Page cannot be
     * found: the slugs the store is installed with.
     */
    public const BULK_ORDER_PATH = '/bulk-order';

    public const MY_ORDERS_PATH = '/my-orders';

    public const PACKET_BASKET_PATH = '/packet-basket';

    /** Where the Bulk Order form's continue button leads. */
    public const CHECKOUT_PATH = '/checkout';

    /** Where the header's search form sends its query. */
    public const SEARCH_PATH = '/search';

    /** Where the header's search asks for its live dropdown's matches. */
    public const SEARCH_SUGGESTIONS_PATH = '/search/suggestions';

    /** Where the My Orders Block asks for a One-Time Code, trades it for Signing in, and signs out. */
    public const SIGN_IN_CODE_PATH = '/sign-in/code';

    public const SIGN_IN_PATH = '/sign-in';

    public const SIGN_OUT_PATH = '/sign-out';

    /** Where the Contact Form Block posts a question. */
    public const ENQUIRY_PATH = '/enquiries';

    public function __construct(
        private StoreSettings $store,
        private HeaderSettings $header,
        private FooterSettings $footer,
        private Catalogue $catalogue,
    ) {}

    /**
     * @return array{name: string, tagline: string, logoUrl: ?string, hotline: ?array{number: string, href: string}, whatsapp: ?array{number: string, href: string}, email: string, address: string, hours: string}
     */
    public function store(): array
    {
        return [
            'name' => $this->store->name,
            'tagline' => $this->store->tagline,
            'logoUrl' => $this->store->logoUrl(),
            'hotline' => $this->phone($this->store->hotline, fn (string $e164): string => 'tel:'.$e164),
            'whatsapp' => $this->phone($this->store->whatsapp, fn (string $e164): string => 'https://wa.me/'.ltrim($e164, '+')),
            'email' => $this->store->email,
            'address' => $this->store->address,
            'hours' => $this->store->hours,
        ];
    }

    /**
     * @return array{pill: ?string, message: string}|null
     */
    public function announcement(): ?array
    {
        if (! $this->header->isAnnouncementShown()) {
            return null;
        }

        return [
            'pill' => filled($this->header->announcementPill) ? $this->header->announcementPill : null,
            'message' => $this->header->announcementMessage,
        ];
    }

    /**
     * @return array{header: list<mixed>, footer: array<string, mixed>}
     */
    public function menus(string $host): array
    {
        $menus = new StorefrontMenus($this->header, $this->footer, $host);

        return [
            'header' => $menus->header(),
            'footer' => $menus->footer(),
        ];
    }

    /**
     * What the Cart, held in the browser (ADR-0029), is priced from on every
     * page, since the header shows its total wherever the buyer is: every
     * published Product sold পাইকারি, with its own Tiers and whether it can
     * be had now, and the Delivery Bands. Prices and fees in minor units.
     *
     * @return array{deliveryBands: list<array{min: int, max: ?int, fee: int}>, products: list<array{id: int, isAvailable: bool, tiers: list<array{min: int, max: ?int, price: int}>}>}
     */
    public function pricing(): array
    {
        return [
            'deliveryBands' => array_map(
                fn (DeliveryBand $band): array => ['min' => $band->min, 'max' => $band->max, 'fee' => $band->fee],
                PriceBook::current()->deliveryBands,
            ),
            'products' => $this->catalogue->soldAs(OrderKind::Bulk)
                ->map(fn (Product $product): array => CatalogueProps::cartProduct($product))
                ->values()
                ->all(),
        ];
    }

    /**
     * What the retail Cart, held in the browser beside the bulk one
     * (ADR-0034), is priced from on every page, since the header counts its
     * Packs wherever the buyer is: every Pack ticked খুচরা of every published
     * Product sold খুচরা (ADR-0036), with whether it can be ordered now, and
     * the retail Delivery Charge and threshold. A Product with none sends
     * nothing here, and that is how the storefront knows it is not sold
     * খুচরা. Money in minor units.
     *
     * @return array{deliveryCharge: int, freeDeliveryFrom: ?int, packs: list<array{id: int, productId: int, productName: string, href: string, imageUrl: ?string, grams: int, mrp: int, price: int, isOrderable: bool}>}
     */
    public function retailPricing(): array
    {
        $book = RetailPriceBook::current();

        return [
            'deliveryCharge' => $book->deliveryCharge,
            'freeDeliveryFrom' => $book->freeDeliveryFrom,
            'packs' => $this->catalogue->soldAs(OrderKind::Retail)
                ->flatMap(fn (Product $product): Collection => $product->packs
                    ->filter(fn (Pack $pack): bool => $pack->servesAs(OrderKind::Retail))
                    ->map(fn (Pack $pack): array => CatalogueProps::cartPack($product, $pack)))
                ->values()
                ->all(),
        ];
    }

    /**
     * Bulk Order, the Packet Basket and My Orders are found by their role, so the buttons
     * follow a renamed slug; one not published leaves its button at the
     * installed slug.
     *
     * @return array{bulkOrder: string, packetBasket: string, myOrders: string, checkout: string, search: string, searchSuggestions: string, signInCode: string, signIn: string, signOut: string, enquiry: string}
     */
    public function links(): array
    {
        $paths = Page::query()
            ->published()
            ->whereIn('system_role', [SystemPage::BulkOrder, SystemPage::PacketBasket, SystemPage::MyOrders])
            ->get(['slug', 'system_role'])
            ->mapWithKeys(fn (Page $page): array => [$page->system_role->value => $page->path()]);

        return [
            'bulkOrder' => $paths->get(SystemPage::BulkOrder->value, self::BULK_ORDER_PATH),
            'packetBasket' => $paths->get(SystemPage::PacketBasket->value, self::PACKET_BASKET_PATH),
            'myOrders' => $paths->get(SystemPage::MyOrders->value, self::MY_ORDERS_PATH),
            'checkout' => self::CHECKOUT_PATH,
            'search' => self::SEARCH_PATH,
            'searchSuggestions' => self::SEARCH_SUGGESTIONS_PATH,
            'signInCode' => self::SIGN_IN_CODE_PATH,
            'signIn' => self::SIGN_IN_PATH,
            'signOut' => self::SIGN_OUT_PATH,
            'enquiry' => self::ENQUIRY_PATH,
        ];
    }

    /**
     * A number as the Merchant typed it and the address it is reached on,
     * or null when nothing usable was typed.
     *
     * @param  callable(string): string  $href
     * @return array{number: string, href: string}|null
     */
    private function phone(string $number, callable $href): ?array
    {
        $e164 = PhoneNumber::normalise($number);

        return $e164 === null ? null : ['number' => $number, 'href' => $href($e164)];
    }
}
