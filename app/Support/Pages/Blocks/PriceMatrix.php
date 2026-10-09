<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: "কোন চায়ে কত ছাড়" — every published Product by Category
 * against its own Tiers, the Tier the Cart is in highlighted. Each Product
 * owns its ranges (ADR-0031), so each row is that Product's ladder rather
 * than one grid with shared columns.
 */
class PriceMatrix extends PageBlock
{
    public static function type(): string
    {
        return 'price_matrix';
    }

    public static function label(): string
    {
        return 'Price matrix';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedTableCells;
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
            'categories' => CatalogueProps::stockedCategories(app(Catalogue::class)),
        ];
    }
}
