<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            if ($verification->isPaid()) {
                $this->flagIfPaidTwice($current);
            }

            return $current;
        });
    }

    /**
     * Money that moved twice is recorded as it is, because it happened, and
     * the Order is flagged on its own trail, because somebody must refund
     * one: the second Paid Payment is a fact about the money, never a reason
     * to record it as anything else (ADR-0013). Nobody caused it — a
     * Verification runs from a page, a Notification or the sweep — so the
     * entry names no User, and the log carries the same word for whoever
     * watches it rather than the trail.
     */
    private function flagIfPaidTwice(Payment $paid): void
    {
        $order = $paid->order;

        if (! $order->isPaidTwice()) {
            return;
        }

        $references = $order->paidPayments()->pluck('invoice_reference')->all();

        Log::warning("Order [{$order->order_number}] was paid twice.", ['payments' => $references]);

        activity()
            ->performedOn($order)
            ->event('paid_twice')
            ->withProperties(['payments' => $references])
            ->log("Order {$order->order_number} was paid twice; one payment must be refunded.");
    }
}
