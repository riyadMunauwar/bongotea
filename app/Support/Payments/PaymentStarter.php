<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Settings\PaymentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Start a Payment for an Order under the method it chose, and say where the
 * Customer goes next.
 *
 * Runs after every Capture and every choice on the Retry Page alike, and
 * answers the gateway's page only when there is one to send the Customer to:
 * the method is online, has a driver, the Order's Page offers it now, and
 * the Order is not already Paid. A Payment is created Pending for the
 * Order's total before the gateway is asked, so an attempt the gateway never
 * answered is still on the record; a gateway that could not be asked, or
 * refused, marks it Failed with what was said, and the Customer lands on the
 * Retry Page with their Order kept (ADR-0013).
 *
 * One refusal is the Customer's to hear rather than the record's to keep: a
 * Payment on the Order still Pending and only seconds old means they are
 * about to pay twice, and starting another is refused with a word on the
 * Retry Page instead.
 */
class PaymentStarter
{
    public function __construct(private readonly PaymentSettings $settings) {}

    /**
     * The gateway's page to send the Customer to, or null where no Payment
     * could be started and `Order::isUnpaid()` decides the page instead.
     *
     * @throws PaymentStillPending while another Payment on the Order is Pending and younger than the wait.
     */
    public function start(Order $order): ?string
    {
        $method = $order->payment_method;
        $gateway = $method->gateway();

        if (! $method->isOnline() || $gateway === null || ! in_array($method, $order->retryPaymentMethods(), true) || $order->isPaid()) {
            return null;
        }

        $payment = $this->createPending($order);

        try {
            /** No Notification endpoint exists yet to name; a driver that takes one is handed nothing until it does. */
            $started = $gateway->start($payment, $payment->processingUrl(), null);
        } catch (PaymentNotStarted $exception) {
            Log::warning($exception->getMessage(), ['order' => $order->order_number, 'payment' => $payment->invoice_reference]);

            $payment->update([
                'state' => PaymentState::Failed,
                'start_response' => $exception->response,
            ]);

            return null;
        }

        $payment->update(['start_response' => $started->response]);

        return $started->redirectUrl;
    }

    /**
     * A Pending Payment for the Order's total, numbered under the Order's
     * lock so two attempts a second apart cannot take the same invoice
     * reference — and refused under the same lock while one is Pending and
     * only seconds old, so two taps a second apart cannot both start one.
     *
     * @throws PaymentStillPending
     */
    private function createPending(Order $order): Payment
    {
        return DB::transaction(function () use ($order): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->recentPendingPayment() !== null) {
                throw new PaymentStillPending;
            }

            return $locked->payments()->create([
                'method' => $locked->payment_method,
                'state' => PaymentState::Pending,
                'amount' => $locked->total,
                'currency' => $this->settings->currencyCode(),
                'invoice_reference' => Payment::nextInvoiceReference($locked),
            ]);
        });
    }
}
