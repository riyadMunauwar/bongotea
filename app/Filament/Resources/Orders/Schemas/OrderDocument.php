<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Schemas\Components\Callout;
use Filament\Support\Icons\Heroicon;

/**
 * The parts the view and edit screens hold in common.
 *
 * Both screens are the same document — the same blocks in the same places, so
 * that opening the editor moves nothing on screen except which cells accept
 * typing. Anything identical between them belongs here rather than being
 * written twice and drifting apart.
 */
class OrderDocument
{
    /**
     * Statuses that mean "do not send this parcel".
     *
     * The document is deliberately shaped to look routine, which is exactly why
     * these need more than the status badge: the cost of skimming past one is a
     * courier delivering goods nobody is going to pay for.
     *
     * @return array<int, Callout>
     */
    public static function warnings(): array
    {
        return [
            Callout::make('Cancelled — this order will not be fulfilled.')
                ->description('The order genuinely happened, so it stays on the books. Do not hand it to a courier.')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->danger()
                ->visible(fn (?Order $record): bool => $record?->status === OrderStatus::Cancelled)
                ->columnSpanFull(),

            /**
             * Reachable because OrderResource drops the SoftDeletingScope from
             * its route binding, so a trashed Order still opens rather than
             * 404ing. A deleted Order is one that should never have existed —
             * a different thing from a Cancelled one, and both can be true.
             */
            Callout::make('Deleted — this order has been removed.')
                ->description('Restore it before working it. A deleted order is one that should never have existed, which is not the same as a cancelled one.')
                ->icon(Heroicon::OutlinedTrash)
                ->danger()
                ->visible(fn (?Order $record): bool => $record?->trashed() ?? false)
                ->columnSpanFull(),
        ];
    }
}
