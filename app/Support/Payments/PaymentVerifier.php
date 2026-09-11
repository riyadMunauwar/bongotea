<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Tracking\MomentRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function __construct(private readonly MomentRecorder $moments) {}

    /**
     * The Payment as it stands once the gateway has been asked and the answer
     * recorded — and, where that answer was Paid, once the Order's Purchase
     * has been written to the tracking outbox.
     *
     * The Moment is reported by whoever's Verification left the Payment Paid,
     * including a caller that found another had settled it first: the money
     * moved either way, and the outbox's own index is what keeps it to one
     * Purchase.
     */
    public function verify(Payment $payment): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $verification = $payment->gateway()->verify($payment);

        $settled = $this->settle($payment, function (Payment $current) use ($verification): void {
            $current->verification_response = $verification->response;
            $current->gateway_reference ??= $verification->gatewayReference;

            if ($verification->isPaid()) {
                $current->state = PaymentState::Paid;
                $current->verified_at = now();
            } elseif ($verification->state !== PaymentState::Pending) {
                $current->state = $verification->state;
            }

            $current->save();

            if ($current->state->isSettled()) {
                $current->logOutcome();
            }

            if ($verification->isPaid()) {
                $this->flagIfPaidTwice($current);
            }
        });

        if ($settled->isPaid()) {
            $this->reportPaid($settled);
        }

        return $settled;
    }

    /**
     * One final Verification, and Expired if the gateway still cannot answer
     * for the Payment: what the sweep does with a Pending Payment older than
     * a day, so the queue is not full of Payments nothing will ever resolve.
     *
     * The gateway's answer wins where it gives one — Paid a day late is still
     * Paid — and Expired is written the way every other outcome is, under
     * the Order's lock against a fresh read, so a Notification landing at
     * the same moment cannot be overwritten by the giving-up.
     */
    public function verifyOrExpire(Payment $payment): Payment
    {
        $verified = $this->verify($payment);

        if (! $verified->isPending()) {
            return $verified;
        }

        return $this->settle($verified, function (Payment $current): void {
            $current->update(['state' => PaymentState::Expired]);
            $current->logOutcome();
        });
    }

    /**
     * Write an outcome onto a Payment under its Order's lock, against a fresh
     * read, and only while it is still Pending: whichever caller writes first
     * decides, and every later one finds nothing left to decide. The Payment
     * as it stands afterwards, whoever wrote it.
     *
     * @param  callable(Payment): void  $write
     */
    private function settle(Payment $payment, callable $write): Payment
    {
        return DB::transaction(function () use ($payment, $write): Payment {
            Order::query()->lockForUpdate()->findOrFail($payment->order_id);

            $current = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($current->isPending()) {
                $write($current);
            }

            return $current;
        });
    }

    /**
     * The Paid Moment, outside the lock the outcome was written under and
     * after it: the row is the Order's to report, not this transaction's, and
     * a Provider is never spoken to from here regardless.
     *
     * Recorded by whoever's Verification left the Payment Paid, which is any
     * of the three — the Processing Page, a gateway's Notification, the
     * sweep — and by all three where they ran at once. Only the first write
     * lands; the outbox's unique index is what makes the rest no-ops, so none
     * of them has to know about the others.
     *
     * Caught and reported rather than allowed to raise, for the reason the
     * Capture Moment is: the money has already moved and been recorded, and
     * an exception here would fail a Verification over a row. A gateway that
     * is not answered 200 retries, and a sweep that throws leaves a settled
     * Payment looking unswept.
     */
    private function reportPaid(Payment $paid): void
    {
        try {
            $this->moments->paid($paid);
        } catch (Throwable $exception) {
            report($exception);
        }
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
