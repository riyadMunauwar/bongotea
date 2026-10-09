<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: a bar per Delivery Band, the Cart's own marked and the free
 * one highlighted. The Merchant writes only the wording; the bands are the
 * ones every storefront page is sent for pricing the Cart, so the chart and
 * the Cart can never show two different tables.
 */
class DeliveryChart extends PageBlock
{
    public static function type(): string
    {
        return 'delivery_chart';
    }

    public static function label(): string
    {
        return 'Delivery chart';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedTruck;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
        ];
    }
}
