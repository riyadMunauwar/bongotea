<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\PaymentVerifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Check with gateway": an operator on the call resolving a Pending Payment
 * now rather than waiting for the sweep.
 *
 * It runs the driver's own Verification through `PaymentVerifier`, exactly as
 * the Processing Page, a Notification and the sweep do, so the answer is
 * recorded once whichever of the four asks first (ADR-0013). The operator
 * decides nothing here; the gateway does. Offered only while the Order has a
 * Payment nothing has been verified about yet, because a settled Payment is
 * never asked about again.
 */
class CheckWithGatewayAction
{
    public static function make(): Action
    {
        return Action::make('checkWithGateway')
            ->label('Check with gateway')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Order $record): bool => $record->pendingPayments()->exists())
            ->action(function (Order $record, PaymentVerifier $verifier): void {
                $checked = $record->pendingPayments()->get()->map(function (Payment $payment) use ($verifier, $record): Payment {
                    $verified = $verifier->verify($payment);

                    self::logCheck($record, $verified);

                    return $verified;
                });

                $record->refresh();

                self::notify($checked->last());
            });
    }

    /**
     * The check itself goes on the Order's trail, whatever the gateway said:
     * the outcome, when there is one, is logged by the Verification, but a
     * gateway that still cannot answer leaves nothing behind, and the operator
     * asking is still worth knowing on the next call.
     */
    private static function logCheck(Order $order, Payment $payment): void
    {
        activity()
            ->performedOn($order)
            ->causedBy(auth()->user())
            ->event('payment_checked')
            ->withProperties([
                'payment' => $payment->invoice_reference,
                'state' => $payment->state->value,
            ])
            ->log("Checked payment {$payment->invoice_reference} with {$payment->method->getLabel()}: {$payment->state->getLabel()}.");
    }

    /**
     * Titled with the state so the operator can act on the word alone: Paid
     * settles the call, Still pending means ask again in a minute, and the
     * rest mean "offer cash or another method".
     */
    private static function notify(Payment $payment): void
    {
        $notification = Notification::make()
            ->title($payment->isPending() ? 'Still pending' : $payment->state->getLabel())
            ->body(match ($payment->state) {
                PaymentState::Paid => "{$payment->method->getLabel()} confirms payment {$payment->invoice_reference}. The order is paid.",
                PaymentState::Pending => "{$payment->method->getLabel()} could not confirm payment {$payment->invoice_reference} yet. It will be asked again; try once more in a minute.",
                PaymentState::Failed => "{$payment->method->getLabel()} did not take payment {$payment->invoice_reference}.",
                PaymentState::Cancelled => "The customer cancelled payment {$payment->invoice_reference} at {$payment->method->getLabel()}.",
                PaymentState::Expired => "Payment {$payment->invoice_reference} expired unanswered at {$payment->method->getLabel()}.",
            });

        /**
         * Not `PaymentState::getColor()`: on the record Failed, Cancelled and
         * Expired are grey history, but to the operator who just asked they
         * are bad news to act on now, so they are red here.
         */
        match ($payment->state) {
            PaymentState::Paid => $notification->success(),
            PaymentState::Pending => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }
}
