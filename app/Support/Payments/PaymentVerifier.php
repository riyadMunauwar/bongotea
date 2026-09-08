<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Verification: the system asking a gateway, in a call of its own, whether a
 * Payment is Paid, and recording the answer.
 *
 * The one thing that can mark a Payment Paid, and it runs from three places
 * that all say the same thing: the Processing Page, a Notification, and the
 * sweep. Those three can run at once for one Payment, so the gateway is asked
 * outside any lock and the outcome is written under the Order's lock against
 * a fresh read: whichever caller writes first decides, and every later one
 * finds nothing left to decide. Only a Pending Payment is asked about at all;
 * Paid, Failed, Cancelled and Expired are settled.
 */
class PaymentVerifier
{
    /**
     * The Payment as it stands once the gateway has been asked and the answer
     * recorded.
     */
    public function verify(Payment $payment): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $verification = $payment->gateway()->verify($payment);

        return DB::transaction(function () use ($payment, $verification): Payment {
            Order::query()->lockForUpdate()->findOrFail($payment->order_id);

            $current = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if (! $current->isPending()) {
                return $current;
            }

            $current->verification_response = $verification->response;
            $current->gateway_reference ??= $verification->gatewayReference;

            if ($verification->isPaid()) {
                $current->state = PaymentState::Paid;
                $current->verified_at = now();
            } elseif ($verification->state !== PaymentState::Pending) {
                $current->state = $verification->state;
            }

            $current->save();

            return $current;
        });
    }
}
