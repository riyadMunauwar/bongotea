<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Support\Checkout\CheckoutLayout;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Everything one Page decides about its checkout, in the order a merchant
 * reads it.
 *
 * One class per concern beside this one — what is sold, how it is arranged,
 * what is asked, what delivery costs, how it looks — so that changing a
 * question never means reading past the delivery charges. This class holds the
 * arrangement and nothing else: which concerns the tab carries, and in what
 * order they are met.
 *
 * A Checkout Config is one thing a Page decides, which is why these stay inside
 * one tab rather than being promoted alongside the Page itself. What the
 * concerns get instead is a nested tab each, so one is on screen at a time and
 * the appearance controls are reachable without scrolling past every question
 * the merchant has ever authored.
 */
class CheckoutTab
{
    public static function make(): Tab
    {
        /** Its own columns, because Filament's default is invisible in the PHP — see .ai/rules/resources.md. */
        return Tab::make('Checkout')
            ->columns(1)
            ->schema([
                /**
                 * Not contained, because the tab it sits in is
                 * already a card: a second one around the same
                 * content would draw a border for a nesting the
                 * merchant can already see in the two rows of
                 * tabs.
                 */
                Tabs::make()
                    ->contained(false)
                    ->tabs([
                        /**
                         * First, because it is the largest
                         * decision on the tab: what the whole
                         * form looks like, before what it sells.
                         */
                        Tab::make('Layout')
                            ->columns(1)
                            ->schema([
                                LayoutChoice::control(),
                            ]),

                        Tab::make('Products')
                            ->columns(1)
                            ->schema([
                                /**
                                 * First, and before the picker it
                                 * gates: the switch is what decides
                                 * whether products can be picked
                                 * from at all, so a merchant reading
                                 * down meets the cause before the
                                 * effect.
                                 */
                                Products::listShown(),

                                Products::sold(),

                                Products::picker(),

                                /**
                                 * After the switch it depends on, for
                                 * the reason the switch comes before
                                 * the picker: a merchant meets the
                                 * cause before the effect.
                                 */
                                Products::preTicked(),
                            ]),

                        Tab::make('Questions')
                            ->columns(1)
                            ->schema([
                                Questions::asked(),
                            ]),

                        Tab::make('Delivery')
                            ->columns(1)
                            ->schema([
                                DeliveryOptions::offered(),
                            ]),

                        /**
                         * After delivery, because that is the
                         * order the customer meets them in on
                         * the form: how the parcel arrives,
                         * then how it is paid for.
                         */
                        Tab::make('Payment')
                            ->columns(1)
                            ->schema([
                                PaymentMethods::offered(),

                                PaymentMethods::logos(),

                                PaymentMethods::loneCash(),
                            ]),

                        /**
                         * After payment, because the last of what
                         * the checkout says is what each method is
                         * called; and before the colours, because a
                         * merchant reads a page before they restyle
                         * it. Every string the checkout says lives
                         * here and nowhere else.
                         */
                        Tab::make('Words')
                            ->columns(1)
                            ->schema([
                                /**
                                 * In the order the customer meets
                                 * them on the form: the button they
                                 * tap, the totals above it, and how
                                 * they pay.
                                 */
                                Words::button(),

                                Words::totals(),

                                Words::payment(),

                                /**
                                 * Then one section per layout that
                                 * owns words of its own, in the
                                 * design's order, each shown only
                                 * while its layout is chosen. Read
                                 * off the enum, so a layout gaining
                                 * words is a change to the layout
                                 * and not to this arrangement.
                                 */
                                ...array_map(Words::ownedBy(...), CheckoutLayout::owningWords()),
                            ]),

                        Tab::make('Look')
                            ->columns(1)
                            ->schema([
                                Look::section(),
                            ]),

                        /**
                         * Beside Look, because both are how the checkout
                         * looks rather than what it asks — but its own tab
                         * and not a twelfth token: the Look tab is the
                         * eleven Style Tokens and nothing else, and turning
                         * the ground off is structural. The toggle is first,
                         * the cause the padding, width and alignment controls
                         * the parent feature adds are the effect of.
                         */
                        Tab::make('Container')
                            ->columns(1)
                            ->schema([
                                Container::withContainer(),

                                /**
                                 * After the toggle, in the order the two boxes
                                 * nest: the ground around the card, then the
                                 * card's own inner padding. Together with the
                                 * toggle above they are the flush recipe.
                                 */
                                Container::groundPadding(),

                                Container::cardPadding(),
                            ]),
                    ]),
            ]);
    }
}
