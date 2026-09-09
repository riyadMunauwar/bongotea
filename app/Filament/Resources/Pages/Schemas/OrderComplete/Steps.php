<?php

namespace App\Filament\Resources\Pages\Schemas\OrderComplete;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\OrderComplete\OrderCompleteConfig;
use App\Support\OrderComplete\OrderCompleteStep;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * What the Timeline layout's tracker says: four steps, each a label and a
 * timing, in the merchant's own words.
 *
 * Four fixed rows rather than a repeater, because the tracker is drawn with
 * four positions whatever is typed: there is nothing to add, reorder or
 * delete, only words to change. Each box shows the built-in words as its
 * placeholder, so what is in force reads without saving to find out, and an
 * empty box keeps them. Asked for only on a layout that draws the tracker,
 * following the live layout select.
 */
class Steps
{
    public static function section(): Section
    {
        return Section::make('Steps')
            ->description('The four steps of what happens next, drawn as a tracker under the headline. The first is the order being placed, which is already done. Anything left empty keeps the page\'s own words.')
            ->columns(1)
            ->visible(fn (Get $get): bool => LayoutChoice::selected($get)->drawsSteps())
            /**
             * Riding along while hidden, so the words a merchant wrote
             * survive a move to a layout that draws no tracker and are
             * there again the day the page is moved back — see
             * .ai/rules/schemas-order-complete.md.
             */
            ->dehydratedWhenHidden()
            ->schema(array_map(self::row(...), array_keys(OrderCompleteConfig::DEFAULT_STEPS)));
    }

    /**
     * One step's two boxes side by side, at the position the tracker draws
     * them.
     */
    private static function row(int $position): Grid
    {
        $default = OrderCompleteConfig::DEFAULT_STEPS[$position];

        return Grid::make(2)
            ->schema([
                TextInput::make('order_complete_config.steps.'.$position.'.label')
                    ->label('Step '.($position + 1))
                    ->placeholder($default['label'])
                    ->rules([RefusedBy::rule(OrderCompleteStep::labelRefusal(...))]),

                TextInput::make('order_complete_config.steps.'.$position.'.timing')
                    ->label('Step '.($position + 1).' timing')
                    ->placeholder($default['timing'])
                    ->rules([RefusedBy::rule(OrderCompleteStep::timingRefusal(...))]),
            ]);
    }
}
