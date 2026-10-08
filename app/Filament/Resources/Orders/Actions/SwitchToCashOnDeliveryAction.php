<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Switch to Cash on Delivery": a Customer who cannot pay online still
 * receives the parcel.
 *
 * The same thing the Customer does for themselves on the Retry Page, done by
 * the operator on the call: the method is written onto the Order, and the
 * Order's own log records the switch, old and new, with the operator as its
 * causer. Switching is not starting a Payment, so nothing is said to any
 * gateway and it is never held off. Offered only on an Unpaid Order: a Paid
 * one has nothing to switch, and a Cash on Delivery one already is.
 *
 * A Pending Payment on the Order is left as it stands — the sweep still asks
 * about it, and one that turns out Paid is recorded Paid all the same. The
 * confirmation says so, because that is the one case where the courier must
 * not also collect.
 */
class SwitchToCashOnDeliveryAction
{
    public static function make(): Action
    {
        return Action::make('switchToCashOnDelivery')
            ->label('Switch to Cash on Delivery')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')

            /**
             * Changing how money is collected on a live Order is not an everyday
             * verb, so it needs `switch to cash on delivery` on top of the
             * `work orders` floor. Folded into `visible()` as the first
             * condition, so a User without it never sees the action rather than
             * seeing it refused on click; the rest keeps it to an Unpaid Order.
             */
            ->visible(fn (Order $record): bool => (auth()->user()?->can(Permission::SwitchToCashOnDelivery) ?? false)
                && $record->isUnpaid())
            ->requiresConfirmation()
            ->modalHeading('Switch this order to Cash on Delivery?')
            ->modalDescription(fn (Order $record): string => $record->pendingPayments()->exists()
                ? 'The courier will collect the total at the door. A payment is still pending with the gateway; if it turns out to have been taken, the order will show as paid and nothing must be collected.'
                : 'The courier will collect the total at the door. The order keeps its figures and its place in the queue.')
            ->modalSubmitActionLabel('Switch to Cash on Delivery')
            ->action(function (Order $record): void {
                /**
                 * Asked again against a fresh read, as the Retry Page asks: a
                 * Notification or the sweep may have marked a Payment Paid
                 * between the screen being drawn and the button pressed, and
                 * a Paid Order switched to cash is one the courier collects
                 * for twice.
                 */
                if (! $record->refresh()->isUnpaid()) {
                    Notification::make()
                        ->title('Nothing to switch')
                        ->body("Order {$record->order_number} is no longer unpaid.")
                        ->warning()
                        ->send();

                    return;
                }

                $record->update(['payment_method' => PaymentMethod::CashOnDelivery]);

                Notification::make()
                    ->title('Switched to Cash on Delivery')
                    ->body("Order {$record->order_number} will be collected at the door.")
                    ->success()
                    ->send();
            });
    }
}
