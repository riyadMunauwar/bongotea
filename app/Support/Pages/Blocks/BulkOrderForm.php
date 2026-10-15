<?php

namespace App\Support\Pages\Blocks;

use App\Enums\OrderKind;
use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: the Cart as a form — every published Product by Category,
 * each with −/+, a typed quantity and, switched on, quick-set chips, beside
 * a summary of the whole Cart and, switched on, its ladder of weights. The
 * Merchant writes the heading and flips the switches; the Products and
 * every figure are read from the catalogue and worked out in the browser.
 */
class BulkOrderForm extends PageBlock
{
    public static function type(): string
    {
        return 'bulk_order_form';
    }

    public static function label(): string
    {
        return 'Bulk order form';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedShoppingCart;
    }

    public static function fields(): array
    {
        return [
            self::cartInput(OrderKind::Bulk),
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
            Toggle::make('quick_chips')
                ->label('Offer quick-set chips (1, 5, 10, 20 kg)')
                ->default(true),
            Toggle::make('show_tier_list')
                ->label('Show the price steps in the summary')
                ->default(true),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'cart' => self::cart($data, OrderKind::Bulk),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'quickChips' => (bool) ($data['quick_chips'] ?? true),
            'showTierList' => (bool) ($data['show_tier_list'] ?? true),
            'categories' => CatalogueProps::stockedBulkCategories(app(Catalogue::class)),
        ];
    }
}
