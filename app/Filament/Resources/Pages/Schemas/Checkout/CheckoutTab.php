<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

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
                         * First, and before the picker it gates:
                         * the layout is what decides whether
                         * products can be picked from at all, so
                         * a merchant reading left to right meets
                         * the cause before the effect.
                         */
                        Tab::make('Layout')
                            ->columns(1)
                            ->schema([
                                LayoutChoice::control(),
                            ]),

                        Tab::make('Products')
                            ->columns(1)
                            ->schema([
                                Products::sold(),

                                Products::picker(),
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

                        Tab::make('Appearance')
                            ->columns(1)
                            ->schema([
                                Appearance::section(),
                            ]),
                    ]),
            ]);
    }
}
