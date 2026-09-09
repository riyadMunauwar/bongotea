<?php

namespace App\Filament\Resources\Pages\Schemas\OrderComplete;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\OrderComplete\OrderCompleteConfig;
use App\Support\OrderComplete\OrderCompleteTile;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * What the Cards grid layout's tiles say: three tiles, each a title and a
 * line beneath it, in the merchant's own words.
 *
 * Three fixed rows rather than a repeater, because the grid is drawn with
 * three tiles whatever is typed: there is nothing to add, reorder or delete,
 * only words to change. Each box shows the built-in words as its
 * placeholder, so what is in force reads without saving to find out, and an
 * empty box keeps them. Asked for only on a layout that draws the tiles,
 * following the live layout select.
 */
class Tiles
{
    public static function section(): Section
    {
        return Section::make('Tiles')
            ->description('The three tiles under the headline, each a title and a line. The first says the order is received. Anything left empty keeps the page\'s own words.')
            ->columns(1)
            ->visible(fn (Get $get): bool => LayoutChoice::selected($get)->drawsTiles())
            /**
             * Riding along while hidden, so the words a merchant wrote
             * survive a move to a layout that draws no tiles and are there
             * again the day the page is moved back — see
             * .ai/rules/schemas-order-complete.md.
             */
            ->dehydratedWhenHidden()
            ->schema(array_map(self::row(...), array_keys(OrderCompleteConfig::DEFAULT_TILES)));
    }

    /**
     * One tile's two boxes side by side, at the position the grid draws
     * them.
     */
    private static function row(int $position): Grid
    {
        $default = OrderCompleteConfig::DEFAULT_TILES[$position];

        return Grid::make(2)
            ->schema([
                TextInput::make('order_complete_config.tiles.'.$position.'.title')
                    ->label('Tile '.($position + 1))
                    ->placeholder($default['title'])
                    ->rules([RefusedBy::rule(OrderCompleteTile::titleRefusal(...))]),

                Textarea::make('order_complete_config.tiles.'.$position.'.line')
                    ->label('Tile '.($position + 1).' line')
                    ->placeholder($default['line'])
                    ->rows(2)
                    ->rules([RefusedBy::rule(OrderCompleteTile::lineRefusal(...))]),
            ]);
    }
}
