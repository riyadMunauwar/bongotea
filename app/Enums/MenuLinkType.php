<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a Menu item points at.
 *
 * A Page is held by reference, so renaming its slug never breaks the Menu
 * and unpublishing it takes the item off the storefront. An Anchor is a
 * section on a Page (Home when none is named); a URL is typed. A Heading
 * points nowhere and only opens the items beneath it — any item may have
 * children, so a heading is simply the item with no target. Category and
 * Product targets, and All Categories, join this list with the catalogue.
 */
enum MenuLinkType: string implements HasLabel
{
    case Page = 'page';
    case Anchor = 'anchor';
    case Url = 'url';
    case Heading = 'heading';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Page',
            self::Anchor => 'Place on a page',
            self::Url => 'Address',
            self::Heading => 'Heading only',
        };
    }

    /**
     * The types that lead somewhere on their own: every one but Heading.
     *
     * @return list<self>
     */
    public static function targets(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type !== self::Heading));
    }
}
