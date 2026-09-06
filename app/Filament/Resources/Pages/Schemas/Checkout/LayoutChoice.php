<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Support\Checkout\CheckoutLayout;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * How this Page's checkout is arranged.
 *
 * One control, and the reading of it every other concern goes through: the
 * layout is what decides whether products can be picked from at all, so
 * Products asks here rather than reading the box itself.
 */
class LayoutChoice
{
    public static function control(): Select
    {
        /**
         * How the checkout is arranged. It changes what
         * the form draws and nothing about what it asks
         * for, what it charges or how it is styled —
         * every question, the delivery choice, the
         * summary and the error slots are rendered by
         * both.
         */
        return Select::make('checkout_config.layout')
            ->label('Layout')
            ->options(CheckoutLayout::labels())
            ->default(CheckoutLayout::Stacked->value)
            /**
             * Not `required`, for the reason a
             * question's width is not: the list has no
             * empty option to pick, and a Config that
             * arrives without one is read as the
             * arrangement the checkout has always had.
             */
            ->selectablePlaceholder(false)
            ->live()
            /** The chosen layout describes itself, so what it does reads without saving to find out. */
            ->helperText(fn (Get $get): string => self::selected($get)->helperText());
    }

    /**
     * The layout the Checkout tab is currently set to.
     *
     * Read through the same total read a stored Config goes through, so a
     * half-filled create screen and a Config written by another route answer
     * the same way rather than the tab having a fallback of its own.
     */
    public static function selected(Get $get): CheckoutLayout
    {
        return CheckoutLayout::fromStored($get('checkout_config.layout'));
    }
}
