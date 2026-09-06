<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

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
 * one tab rather than being promoted alongside the Page itself.
 */
class CheckoutTab
{
    public static function make(): Tab
    {
        /** Its own columns, because Filament's default is invisible in the PHP — see .ai/rules/resources.md. */
        return Tab::make('Checkout')
            ->columns(1)
            ->schema([
                Products::sold(),

                /**
                 * Between the two product controls rather than after both:
                 * the layout is what decides whether the picker can be
                 * asked about at all, so a merchant reading downwards
                 * meets the cause before the effect.
                 */
                LayoutChoice::control(),

                Products::picker(),

                Questions::asked(),

                DeliveryOptions::offered(),

                Appearance::section(),
            ]);
    }
}
