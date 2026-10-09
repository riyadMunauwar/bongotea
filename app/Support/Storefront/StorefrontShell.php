<?php

namespace App\Support\Storefront;

use App\Enums\PaymentMethod;
use App\Enums\SystemPage;
use App\Models\Page;
use App\Support\PhoneNumber;
use App\Support\Settings\AnnouncementSettings;
use App\Support\Settings\MenuSettings;
use App\Support\Settings\PaymentSettings;
use App\Support\Settings\StoreSettings;

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

    /** Where the header's search form sends its query. */
    public const SEARCH_PATH = '/search';

    public function __construct(
        private StoreSettings $store,
        private AnnouncementSettings $announcement,
        private MenuSettings $menus,
        private PaymentSettings $payments,
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
        if (! $this->announcement->isShown()) {
            return null;
        }

        return [
            'pill' => filled($this->announcement->pill) ? $this->announcement->pill : null,
            'message' => $this->announcement->message,
        ];
    }

    /**
     * @return array{header: list<mixed>, footer: array<string, mixed>}
     */
    public function menus(string $host): array
    {
        $menus = new StorefrontMenus($this->menus, $host);

        return [
            'header' => $menus->header(),
            'footer' => $menus->footer(),
        ];
    }

    /**
     * The methods a buyer can pay by right now, by stored name; the client
     * names them in Bangla. Only switched-on, usable methods, so the footer
     * never advertises one the store cannot take.
     *
     * @return list<string>
     */
    public function paymentMethods(): array
    {
        return PaymentMethod::names($this->payments->usableMethods());
    }

    /**
     * Bulk Order and My Orders are found by their role, so the buttons
     * follow a renamed slug; one not published leaves its button at the
     * installed slug.
     *
     * @return array{bulkOrder: string, myOrders: string, search: string}
     */
    public function links(): array
    {
        $paths = Page::query()
            ->published()
            ->whereIn('system_role', [SystemPage::BulkOrder, SystemPage::MyOrders])
            ->get(['slug', 'system_role'])
            ->mapWithKeys(fn (Page $page): array => [$page->system_role->value => $page->path()]);

        return [
            'bulkOrder' => $paths->get(SystemPage::BulkOrder->value, self::BULK_ORDER_PATH),
            'myOrders' => $paths->get(SystemPage::MyOrders->value, self::MY_ORDERS_PATH),
            'search' => self::SEARCH_PATH,
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
