<?php

namespace App\Filament\Resources\Pages\Schemas\OrderComplete;

use Filament\Schemas\Components\Tabs\Tab;

/**
 * Everything one Page decides about its Order Complete Page, in the order a
 * merchant reads it.
 *
 * One class per concern beside this one — how it is arranged, what it says,
 * where the buttons go — and this class holds the arrangement and nothing
 * else. A top-level tab beside Checkout rather than a nested one inside it,
 * because an Order Complete Config is a second thing a Page decides: the
 * Checkout captures the Order, and this is where the Customer lands
 * afterwards.
 *
 * Sections rather than nested tabs, because three short sections read on one
 * scroll and a tab per section would hide two of them for nothing.
 */
class OrderCompleteTab
{
    public static function make(): Tab
    {
        /** Its own columns, because Filament's default is invisible in the PHP — see .ai/rules/resources.md. */
        return Tab::make('Order complete')
            ->columns(1)
            ->schema([
                /**
                 * First, because it decides which words the
                 * later sections will ask for: a layout that
                 * draws no status line is not asked for one.
                 */
                LayoutChoice::section(),

                Words::section(),

                Buttons::section(),
            ]);
    }
}
