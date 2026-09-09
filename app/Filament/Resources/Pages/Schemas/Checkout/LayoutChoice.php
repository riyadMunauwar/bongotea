<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Filament\Forms\Components\LayoutCards;
use App\Support\Checkout\CheckoutLayout;
use Filament\Schemas\Components\Utilities\Get;

/**
 * How this Page's checkout is arranged.
 *
 * One control, and the reading of it every other concern goes through:
 * Questions asks here whether the arrangement pairs half-width questions, and
 * Words asks here which layout-owned slots to show, rather than reading the
 * box themselves. Whether products are drawn is not the layout's to say — that
 * is the Product List switch on the Products tab.
 */
class LayoutChoice
{
    public static function control(): LayoutCards
    {
        /**
         * The ten, as a grid of cards: a thumbnail, the
         * name and one line on what the layout looks like,
         * so a merchant sees what they are choosing before
         * they choose it. It changes how the form is drawn
         * and nothing about what it asks for or what it
         * charges — every question, the delivery choice,
         * the summary and the error slots are rendered by
         * every one of them, and the Look tab is read the
         * same way by all of them.
         */
        return LayoutCards::make('checkout_config.layout')
            ->label('Layout')
            ->options(CheckoutLayout::labels())
            ->descriptions(CheckoutLayout::descriptions())
            ->thumbnails(CheckoutLayout::thumbnails())
            ->default(CheckoutLayout::Stacked->value)
            /**
             * Not `required`, for the reason a question's
             * width is not: a Config that arrives without
             * one is read as the arrangement the checkout
             * has always had.
             */
            ->live();
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
