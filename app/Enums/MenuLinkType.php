<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a Menu entry points at.
 *
 * A Page is held by reference, so renaming its slug never breaks the Menu
 * and unpublishing it takes the entry off the storefront. An Anchor is a
 * section on a Page (Home when none is named); a URL is typed. A Dropdown
 * points nowhere and opens the entries beneath it; only a header entry may be
 * one. Category and Product targets, and a Dropdown listing every Category
 * on its own, join this list with the catalogue.
 */
enum MenuLinkType: string implements HasLabel
{
    case Page = 'page';
    case Anchor = 'anchor';
    case Url = 'url';
    case Dropdown = 'dropdown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Page',
            self::Anchor => 'Section anchor',
            self::Url => 'URL',
            self::Dropdown => 'Dropdown',
        };
    }

    /**
     * The types an entry that must lead somewhere may take: every one but
     * Dropdown.
     *
     * @return list<self>
     */
    public static function targets(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type !== self::Dropdown));
    }
}
