<?php

namespace App\Support\Pages\Blocks;

use App\Enums\OrderKind;
use App\Models\Cart;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: one Cart as a page (ADR-0038). The Products the Merchant
 * assigned to it, by Category, each with a stepper for every Pack it is sold
 * in that way — Kilograms and the packets they make on a bulk Cart, a count
 * at its Pack Price and MRP on a retail one — beside the summary of that
 * Cart's Contents alone. Whatever the Customer's Contents hold that is not
 * assigned is drawn apart, above them.
 *
 * The Merchant picks the Cart, writes the heading and, for a bulk Cart, flips
 * the quick-set chips and price steps. A Cart stands on one Page at most, so
 * this Block is where its link leads. A closed Cart is drawn closed, its
 * Products left out.
 */
class CartBlock extends PageBlock
{
    public static function type(): string
    {
        return 'cart';
    }

    public static function label(): string
    {
        return 'Cart';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedShoppingCart;
    }

    public static function fields(): array
    {
        return [
            self::cartInput(),
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
            Toggle::make('quick_chips')
                ->label('Offer quick-set chips (1, 5, 10, 20 kg)')
                ->default(true)
                ->visible(fn (Get $get): bool => self::isBulk($get('cart_id'))),
            Toggle::make('show_tier_list')
                ->label('Show the price steps in the summary')
                ->default(true)
                ->visible(fn (Get $get): bool => self::isBulk($get('cart_id'))),
        ];
    }

    public static function props(array $data): array
    {
        $cart = self::namedCart($data);
        $layout = $cart?->isOpen() ? CatalogueProps::cartLayout(app(Catalogue::class), $cart) : ['categories' => [], 'others' => []];

        return [
            'cart' => $cart === null ? null : self::cartProps($cart),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'quickChips' => (bool) ($data['quick_chips'] ?? true),
            'showTierList' => (bool) ($data['show_tier_list'] ?? true),
            ...$layout,
        ];
    }

    /** Whether the Cart picked on the form is a bulk one, whose switches the form then offers. */
    private static function isBulk(mixed $cartId): bool
    {
        return is_numeric($cartId) && Cart::withTrashed()->whereKey((int) $cartId)->where('kind', OrderKind::Bulk)->exists();
    }
}
