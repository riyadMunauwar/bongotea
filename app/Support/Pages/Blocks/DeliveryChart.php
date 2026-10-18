<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Enums\OrderKind;
use App\Enums\PaymentMethod;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: a tab per Delivery Zone of the kind the Merchant picks — bulk
 * or retail (ADR-0039) — and in each a bar per Delivery Band, the Cart's own
 * marked and the free one highlighted. The Merchant writes only the wording
 * and the kind; the zones are the ones every storefront page is sent for
 * pricing the Cart, so the chart and the Cart can never show two different
 * tables.
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

    public static function group(): BlockGroup
    {
        return BlockGroup::Selling;
    }

    public static function description(): string
    {
        return 'A tab per Delivery Zone, a bar per range: what delivery costs, and from where it is free.';
    }

    public static function fields(): array
    {
        return [
            Select::make('kind')
                ->label('Delivery charges')
                ->options([OrderKind::Bulk->value => 'Bulk Delivery Charges', OrderKind::Retail->value => 'Retail Delivery Charges'])
                ->default(OrderKind::Bulk->value)
                ->selectablePlaceholder(false),
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
        ];
    }

    /**
     * The wording, which kind's zones to draw — bulk for a Block saved before
     * there was a choice — and whether the chart may say Cash on Delivery is
     * taken: only while the Merchant has it switched on, so the chart never
     * advertises a method the store cannot take.
     */
    public static function props(array $data): array
    {
        return [
            'kind' => ($data['kind'] ?? null) === OrderKind::Retail->value ? OrderKind::Retail->value : OrderKind::Bulk->value,
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'offersCashOnDelivery' => PaymentMethod::CashOnDelivery->isUsable(),
        ];
    }
}
