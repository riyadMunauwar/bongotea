<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: "হিসাব করে দেখুন" — every published Product by Category with
 * a stepper, and the dark summary panel beside them. It is a view of the one
 * Cart, never a second set of quantities, so playing with the numbers here
 * and ordering are the same thing.
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
            'categories' => CatalogueProps::stockedBulkCategories(app(Catalogue::class)),
        ];
    }
}
