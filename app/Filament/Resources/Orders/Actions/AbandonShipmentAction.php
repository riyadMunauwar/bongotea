<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Abandon parcel": a User who has spoken to the Courier writes the parcel
 * off, and the Order is free to go again.
 *
 * The only thing a User may say about a parcel that the Courier has not said
 * (ADR-0018), and the answer to the one case nothing else resolves: a parcel
 * a Courier never collected, or one held so long there is nothing left to
 * wait for. The sweep gives up asking after a month and still changes
 * nothing, because only a person may say a parcel is dead.
 *
 * Offered from the Order and not from the Shipment's own screen, like every
 * decision about an Order: the Shipment resource is read-only, and a User
 * abandoning a parcel is a User about to send that Order again. It sits
 * beside "Send with courier" on both of the Order's screens, and the two are
 * never offered at once — one is for an Order with a parcel out, the other
 * for an Order with none.
 *
 * What it writes is a time and a name and never a status. The Courier's last
 * word stays exactly as the Courier left it, so a Shipment On Hold and then
 * abandoned goes on saying both; the confirmation says as much, because a
 * User pressing this is entitled to know their decision does not erase the
 * Courier's own account of the parcel.
 */
class AbandonShipmentAction
{
    public static function make(): Action
    {
        return Action::make('abandonShipment')
            ->label('Abandon parcel')
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('danger')

            /**
             * Offered while there is a parcel to write off. Never on a deleted
             * Order, for the reason the booking is not: the Orders screens drop
             * the soft-delete scope so all three open one, and an Order that
             * should never have existed is not one to make decisions about —
             * restoring it first is the answer.
             */
            ->visible(fn (Order $record): bool => ! $record->trashed() && $record->isShipping())
            ->requiresConfirmation()
            ->modalHeading('Write this parcel off?')
            ->modalDescription(fn (Order $record): string => self::whatWillHappen($record))
            ->modalSubmitActionLabel('Abandon parcel')
            ->action(function (Order $record): void {
                /**
                 * Read fresh, as every action on these screens reads: a
                 * webhook or the sweep may have delivered the parcel between
                 * the screen being drawn and the button being pressed, and a
                 * parcel that arrived is not one to write off.
                 */
                $shipment = self::parcelOut($record->refresh());

                if ($shipment === null) {
                    Notification::make()
                        ->title('Nothing to abandon')
                        ->body("No parcel is out for order {$record->order_number} any more.")
                        ->warning()
                        ->send();

                    return;
                }

                $shipment->abandon(auth()->user());

                $record->refresh();

                Notification::make()
                    ->title('Parcel written off')
                    ->body("Parcel {$shipment->invoice_reference} with {$shipment->courier->getLabel()} is abandoned. The order can be sent again.")
                    ->success()
                    ->send();
            });
    }

    /**
     * What the User is about to do, in the Courier's own terms: which parcel,
     * what the Courier last said about it, and what abandoning does and does
     * not change.
     *
     * The Courier's last word is quoted here rather than summarised, because
     * the whole point of abandoning being a time and a name is that the word
     * survives it.
     */
    private static function whatWillHappen(Order $record): string
    {
        $shipment = self::parcelOut($record);

        if ($shipment === null) {
            return 'No parcel is out for this order any more.';
        }

        $lastWord = $shipment->raw_status === null
            ? "{$shipment->courier->getLabel()} has said nothing about it"
            : "{$shipment->courier->getLabel()} last said “{$shipment->raw_status}”";

        return "Parcel {$shipment->invoice_reference} is given up as dead: {$lastWord}, and that stays on the record. "
            .'Your name and the time are written against it, and the order can then be sent again — this does not cancel the order.';
    }

    /**
     * The parcel this Order has out, if it still has one.
     *
     * Asked of the relation so a record the screen already loaded answers
     * without a query, and so the reading of Active is the model's own.
     */
    private static function parcelOut(Order $record): ?Shipment
    {
        return $record->activeShipment;
    }
}
