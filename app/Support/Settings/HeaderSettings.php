<?php

namespace App\Support\Settings;

use App\Enums\OrderKind;
use App\Models\Cart;

/**
 * What is drawn only in the storefront header: which Menu is its navigation
 * (desktop and drawer share it), and the dark announcement bar above it — a
 * short pill and one line of offer, switched on or off as a whole.
 *
 * The header is a place a Menu is assigned to (ADR-0033): `placesOf()` is
 * this group's answer to "is that Menu assigned here", which `MenuPlaces`
 * asks rather than restating.
 */
class HeaderSettings extends SettingsGroup
{
    /** The header's Menu. None draws the header without a navigation row. */
    public ?int $menuId = null;

    /**
     * The Carts the header's খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন
     * buttons open (ADR-0038). Never set, each is the starting Cart of its
     * kind; a button whose Cart is closed is not drawn.
     */
    public ?int $retailCartId = null;

    public ?int $bulkCartId = null;

    public bool $announcementEnabled = true;

    /** The gold tag before the message. Blank draws the message alone. */
    public string $announcementPill = 'অফার';

    public string $announcementMessage = '২০+ কেজিতে প্রতিটি চায়ে ডিস্ট্রিবিউটর দাম';

    public static function key(): string
    {
        return 'header';
    }

    /**
     * The base coerces scalars only, so a damaged row holding a list where
     * the Menu id goes would throw from `load()` on every request. Anything
     * that is not an id reads as no Menu.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        foreach (['menuId', 'retailCartId', 'bulkCartId'] as $id) {
            if (array_key_exists($id, $values)) {
                $values[$id] = is_numeric($values[$id]) ? (int) $values[$id] : null;
            }
        }

        return parent::with($values);
    }

    /**
     * The Cart a header button opens, deleted or not: the one chosen, else
     * the starting Cart of that kind. Whether it is drawn is whether it is
     * open.
     */
    public function cartFor(OrderKind $kind): ?Cart
    {
        $chosen = $kind === OrderKind::Bulk ? $this->bulkCartId : $this->retailCartId;
        $cart = $chosen === null ? null : Cart::withTrashed()->where('kind', $kind)->find($chosen);

        return $cart ?? Cart::startingFor($kind);
    }

    /**
     * Whether the bar is drawn: switched on and saying something.
     */
    public function isAnnouncementShown(): bool
    {
        return $this->announcementEnabled && filled($this->announcementMessage);
    }

    /**
     * The places in the header this Menu is assigned to, as a Merchant
     * reads them.
     *
     * @return list<string>
     */
    public function placesOf(int $menuId): array
    {
        return $this->menuId === $menuId ? ['Header'] : [];
    }
}
