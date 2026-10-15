<?php

namespace App\Support\Pages\Blocks;

use App\Enums\OrderKind;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: the retail Cart (খুচরা কার্ট) as a page — each Pack with a
 * stepper and a remove button, the subtotal, the retail Delivery Charge, the
 * total and how far free delivery is (ADR-0034). The Merchant writes only the
 * wording; the Packs and every figure come from the shared `retailPricing`
 * prop and are worked out in the browser.
 */
class PacketBasket extends PageBlock
{
    public static function type(): string
    {
        return 'packet_basket';
    }

    public static function label(): string
    {
        return 'Packet basket';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedShoppingBag;
    }

    public static function fields(): array
    {
        return [
            self::cartInput(OrderKind::Retail),
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'cart' => self::cart($data, OrderKind::Retail),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
        ];
    }
}
