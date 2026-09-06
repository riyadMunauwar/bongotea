<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * What this Page sells, and whether the customer chooses between them.
 *
 * The two controls are contributed separately because the layout is asked
 * about between them — see `CheckoutTab`, which is where that order is decided.
 */
class Products
{
    public static function sold(): Select
    {
        /**
         * Shows the live links only, and leaves the rest
         * alone: Filament detaches what its own relationship
         * query returned and is missing from the selection
         * (Select::saveRelationshipsAfterStateUpdated), and a
         * soft-deleted Product is in neither. So saving an
         * unrelated field does not quietly drop the link the
         * Pages list is flagging as lost.
         */
        return Select::make('products')
            ->relationship('products', 'name')
            ->multiple()
            ->preload()
            ->searchable()
            ->helperText('[checkout] sells everything linked here, each with its own quantity, unless you let the customer choose below. Link at least one to publish.');
    }

    public static function picker(): Toggle
    {
        /**
         * Whether the customer chooses which of them to
         * buy. Off, so linking a second product sells
         * both together the way this page always has,
         * and offering a choice is something a merchant
         * asks for rather than something a link does to
         * them.
         *
         * No control decides how many products make a
         * choice worth asking about: a page down to one
         * sells it implicitly, which `Page` answers for
         * the form and the capture together.
         *
         * Held shut on a form-only page, which draws no
         * products and so has nothing to put a checkbox
         * beside. Held shut rather than hidden, and
         * dehydrated so the switch survives: a merchant
         * trying the other layout gets their picker back
         * when they switch away from it. The same
         * refusal is made again as the Config is read,
         * because a picker left standing there would
         * compile a rule demanding a choice the form
         * never offered — every order on a live page
         * refused for a box no customer can find.
         */
        return Toggle::make('checkout_config.product_picker')
            ->label('Let the customer choose')
            ->default(false)
            ->disabled(fn (Get $get): bool => ! LayoutChoice::selected($get)->showsProducts())
            ->dehydrated()
            ->helperText(fn (Get $get): string => LayoutChoice::selected($get)->showsProducts()
                ? 'Each product gets a checkbox, unticked, and its quantity appears only once it is ticked. A page selling one product asks nothing and sells it.'
                : 'Not on a form-only page: there are no products on screen to tick. The order is for everything the page sells. Your setting is kept for when you switch back.');
    }
}
