<?php

namespace App\Filament\Resources\Pages\Schemas\OrderComplete;

use App\Support\OrderComplete\OrderCompleteLayout;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * How this Page's Order Complete Page is arranged.
 *
 * One control, built like the Checkout's layout select, and the reading of it
 * every other section goes through: which words a layout draws is the layout's
 * to say, so a section asks here rather than reading the box itself.
 */
class LayoutChoice
{
    public static function section(): Section
    {
        return Section::make('Layout')
            ->columns(1)
            ->schema([
                self::control(),
            ]);
    }

    public static function control(): Select
    {
        /**
         * How the page is arranged. It changes what the
         * page draws and nothing about what the Order
         * contains: the order number, the lines, the
         * totals and how it is paid are drawn by every
         * one of them.
         */
        return Select::make('order_complete_config.layout')
            ->label('Layout')
            ->options(OrderCompleteLayout::labels())
            ->default(OrderCompleteLayout::CenteredClassic->value)
            /**
             * Not `required`: the list has no empty option
             * to pick, and a Config that arrives without
             * one is read as the arrangement the page has
             * always had.
             */
            ->selectablePlaceholder(false)
            ->live()
            /** The chosen layout describes itself, so what it draws reads without saving to find out. */
            ->helperText(fn (Get $get): string => self::selected($get)->helperText());
    }

    /**
     * The layout the Order complete tab is currently set to.
     *
     * Read through the same total read a stored Config goes through, so a
     * half-filled create screen and a Config written by another route answer
     * the same way rather than the tab having a fallback of its own.
     */
    public static function selected(Get $get): OrderCompleteLayout
    {
        return OrderCompleteLayout::fromStored($get('order_complete_config.layout'));
    }
}
