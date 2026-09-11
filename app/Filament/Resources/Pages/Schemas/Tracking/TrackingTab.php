<?php

namespace App\Filament\Resources\Pages\Schemas\Tracking;

use Filament\Schemas\Components\Tabs\Tab;

/**
 * Everything one Page decides about its own tracking, in the order a Merchant
 * reads it.
 *
 * A fourth top-level tab beside Page, Checkout and Order complete, because
 * where a Page reports is a fourth thing a Page decides — not part of the
 * checkout that captures the Order, and not part of the page the Customer
 * lands on afterwards.
 *
 * Read down, it is one question after another: where does this report, what
 * does it report, how much does it say about what was bought, and then — last
 * — exactly what leaves the server, so the answer to the first three can be
 * checked rather than trusted.
 *
 * One class per section beside this one, so a ticket touching the events
 * edits a small file. This class holds the arrangement and nothing else.
 */
class TrackingTab
{
    public static function make(): Tab
    {
        /** Its own columns, because Filament's default is invisible in the PHP — see .ai/rules/resources.md. */
        return Tab::make('Tracking')
            ->columns(1)
            ->schema([
                DestinationChoice::section(),

                Events::section(),

                ContentDetail::section(),

                /** Last, because it is the answer to everything above it. */
                Preview::section(),
            ]);
    }
}
