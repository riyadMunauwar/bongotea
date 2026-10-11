<?php

namespace App\Enums;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

/**
 * What a Menu item points at.
 *
 * A Page, Category or Product is held by reference, so renaming or
 * re-slugging it never breaks the Menu, and hiding it takes the item off
 * the storefront. An Anchor is a section on a Page (Home when none is
 * named); a URL is typed. A Heading points nowhere and only opens the items
 * beneath it — any item may have children, so a heading is simply the item
 * with no target. All Categories points nowhere either: its children are
 * every Category as it stands when the Menu is drawn, never stored.
 */
enum MenuLinkType: string implements HasLabel
{
    case Page = 'page';
    case Category = 'category';
    case Product = 'product';
    case Anchor = 'anchor';
    case Url = 'url';
    case Heading = 'heading';
    case AllCategories = 'all_categories';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Page',
            self::Category => 'Category',
            self::Product => 'Product',
            self::Anchor => 'Place on a page',
            self::Url => 'Address',
            self::Heading => 'Heading only',
            self::AllCategories => 'All Categories',
        };
    }

    /**
     * The model this type's `linkable` target is, or null when it holds none
     * of its own (an Anchor's optional Page is read separately).
     *
     * @return class-string<Model>|null
     */
    public function linkable(): ?string
    {
        return match ($this) {
            self::Page => Page::class,
            self::Category => Category::class,
            self::Product => Product::class,
            default => null,
        };
    }

    /**
     * The types that lead somewhere on their own: every one but Heading and
     * All Categories, which only open the items beneath them.
     *
     * @return list<self>
     */
    public static function targets(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => ! in_array($type, [self::Heading, self::AllCategories], true)));
    }
}
