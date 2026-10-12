<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of place of the store a Visit was a load of. A Page, a Category
 * and a Product are each a record, and a Visit is counted against the record
 * rather than its address, so renaming a Slug never splits its history.
 * Search and the Checkout are one place each, whatever was searched for.
 */
enum PlaceKind: string implements HasLabel
{
    case Page = 'page';
    case Category = 'category';
    case Product = 'product';
    case Search = 'search';
    case Checkout = 'checkout';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Page',
            self::Category => 'Category',
            self::Product => 'Product',
            self::Search => 'Search',
            self::Checkout => 'Checkout',
        };
    }

    /**
     * Whether a place of this kind is a record of its own, rather than one
     * fixed place of the store.
     */
    public function isARecord(): bool
    {
        return in_array($this, [self::Page, self::Category, self::Product], true);
    }
}
