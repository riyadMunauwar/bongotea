<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Enums\OrderKind;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: "হিসাব করে দেখুন" — the Products assigned to one bulk Cart, by
 * Category, with a stepper, and the dark summary panel beside them. It is a
 * view of that Cart's Contents (ADR-0038), never a second set of quantities,
 * so playing with the numbers here and ordering are the same thing, and its
 * button opens that Cart's page. It does not place the Cart: its page is the
 * one its Cart Block stands on. A closed Cart offers nothing.
 */
class PriceCalculator extends PageBlock
{
    public static function type(): string
    {
        return 'price_calculator';
    }

    public static function label(): string
    {
        return 'Price calculator';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedCalculator;
    }

    public static function group(): BlockGroup
    {
        return BlockGroup::Selling;
    }

    public static function description(): string
    {
        return 'A bulk Cart\'s Products with steppers and a running total, to work out an order.';
    }

    public static function fields(): array
    {
        return [
            self::cartInput(OrderKind::Bulk, drawsIt: false),
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
        ];
    }

    public static function props(array $data): array
    {
        $cart = self::namedCart($data, OrderKind::Bulk);
        $isOpen = $cart?->isOpen() ?? false;

        return [
            'cart' => $cart === null ? null : [...self::cartProps($cart), 'href' => $isOpen ? $cart->link() : null],
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'categories' => $isOpen ? CatalogueProps::cartLayout(app(Catalogue::class), $cart)['categories'] : [],
        ];
    }
}
