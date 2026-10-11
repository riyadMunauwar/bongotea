<?php

namespace App\Support\Settings;

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

    public bool $announcementEnabled = true;

    /** The gold tag before the message. Blank draws the message alone. */
    public string $announcementPill = 'অফার';

    public string $announcementMessage = '৫১+ কেজিতে প্রতিটি চায়ে সর্বোচ্চ ছাড়';

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
        if (array_key_exists('menuId', $values)) {
            $values['menuId'] = is_numeric($values['menuId']) ? (int) $values['menuId'] : null;
        }

        return parent::with($values);
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
