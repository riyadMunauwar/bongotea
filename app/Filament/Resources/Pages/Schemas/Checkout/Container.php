<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use Filament\Forms\Components\Toggle;

/**
 * The Checkout's Container: the two boxes the island draws itself inside.
 *
 * The ground is the grey band the design paints behind everything, with the
 * padding around it; the card is the one bordered panel the Customer reads
 * inside it. This tab is where a Merchant dissolves the ground so the Checkout
 * sits flush inside a wrapper they drew themselves — the padding, width and
 * alignment controls the parent feature adds all hang off the toggle below.
 *
 * Deliberately its own tab beside Look rather than a twelfth Style Token: the
 * Look tab is the eleven tokens and nothing else, and turning the ground off is
 * structural rather than a colour or a corner. The controls are contributed one
 * at a time because a concern hands over one component and never a list;
 * `CheckoutTab` holds the order, with the toggle first as the cause the rest
 * are the effect of.
 */
class Container
{
    /**
     * Whether the Checkout draws its ground: the grey band behind the card and
     * the padding around it.
     *
     * The first control on the tab and the cause of everything below it: with
     * the ground on, the Checkout draws the band it always has; with it off,
     * the band and its padding are gone and the card sits flush against
     * whatever the Merchant wrapped the shortcode in. The card, and everything
     * a Customer reads inside it, is untouched either way.
     */
    public static function withContainer(): Toggle
    {
        return Toggle::make('checkout_config.container.enabled')
            ->label('Draw the checkout on its own ground')
            ->default(true)
            /**
             * Refused twice, like everything in a Config: worded here for
             * anything that is not a yes or a no, and dropped for the default
             * (the ground on) on read. A switch cannot type anything else, so
             * this is the refusal a Config written by another route meets.
             */
            ->rule('boolean')
            ->helperText('On, the checkout draws the grey ground behind the card, with padding around it, as designed. Off, that ground and its padding are removed and the card sits flush inside whatever you wrapped [checkout] in. The card itself is unchanged; recolour or clear the ground with the Background token on the Look tab.');
    }
}
