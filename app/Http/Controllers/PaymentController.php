<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\Payments\Notification;
use App\Support\Payments\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseCode;
use Throwable;

/**
 * Where a gateway sends a Customer back, and the Verification that decides
 * where they go from there.
 *
 * Both routes are addressed by our invoice reference in the path and by
 * nothing in the gateway's parameters, sit outside request-forgery
 * protection because the return is a cross-site POST with no session, and
 * print nothing about the Customer, because this is the one address a
 * gateway's own logs hold.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentVerifier $verifier) {}

    /**
     * The Processing Page: says the Payment is being verified, and has the
     * browser call the Verification once drawn, so the Customer sees a
     * "verifying" state rather than a blank wait on the gateway's redirect.
     *
     * The return is read for the gateway's reference and nothing more; the
     * state waits for the Verification. GET as well as POST, so a refresh of
     * the same address draws the same page and is harmless.
     */
    public function processing(Request $request, string $method, Payment $payment): Response
    {
        $this->abortUnlessUnder($method, $payment);

        $reference = $payment->gateway()->paymentReferenceFromReturn($request);

        if ($reference !== null && $payment->gateway_reference === null) {
            $payment->update(['gateway_reference' => $reference]);
        }

        return $this->uncachedPage('checkout.processing', [
            'payment' => $payment,
            'verifyUrl' => $payment->verifyUrl(),
        ]);
    }

    /**
     * The Verification, and where to go next: the Order Complete Page once the
     * Order is paid, the Retry Page while it is not. JSON to the Processing
     * Page's script, a redirect to a client without one, and idempotent for
     * both, so calling it twice costs one call to the gateway and never two
     * Paid Payments.
     */
    public function verify(Request $request, string $method, Payment $payment): JsonResponse|RedirectResponse
    {
        $this->abortUnlessUnder($method, $payment);

        $destination = $this->verifier->verify($payment)->order->followingPageUrl();

        if ($request->expectsJson()) {
            return response()->json(['redirect' => $destination]);
        }

        return redirect()->to($destination, ResponseCode::HTTP_SEE_OTHER);
    }

    /**
     * A Notification: the gateway telling the system on its own that
     * something happened to a Payment. Read through the driver for which
     * Payment it names, then a Verification the system makes decides;
     * nothing the Notification says is ever written down (ADR-0013).
     *
     * Answers 200 whatever happened, so the gateway stops retrying: a
     * Notification naming no Payment the system knows, or claiming a figure
     * that is not the Payment's, is garbage from a guessable address and is
     * logged and ignored rather than asked about.
     */
    public function notify(Request $request, string $method): Response
    {
        $gateway = PaymentMethod::from($method)->gateway();

        abort_if($gateway === null, ResponseCode::HTTP_NOT_FOUND);

        $notification = $gateway->readNotification($request);

        if ($notification === null) {
            return $this->heard();
        }

        $payment = Payment::query()->where('invoice_reference', $notification->invoiceReference)->first();

        if ($payment === null || $payment->method->value !== $method) {
            return $this->ignored("A {$method} notification named [{$notification->invoiceReference}], which is no Payment under it.", $notification);
        }

        if (! $notification->claimsAmountOf($payment)) {
            return $this->ignored("A {$method} notification for Payment [{$payment->invoice_reference}] claimed an amount that is not its own.", $notification);
        }

        /**
         * The gateway's reference is lent to the Verification, not written:
         * it came from a guessable address, so the record takes only the
         * reference the gateway itself names when asked.
         */
        $payment->gateway_reference ??= $notification->gatewayReference;

        try {
            $this->verifier->verify($payment);
        } catch (Throwable $failure) {
            /** Still 200: a retry from the gateway would only fail the same way, and the sweep asks again regardless. */
            report($failure);
        }

        return $this->heard();
    }

    /**
     * What every Notification is answered with: 200 and nothing else, so the
     * gateway stops retrying. What became of the Payment is not its concern.
     */
    private function heard(): Response
    {
        return response()->noContent(ResponseCode::HTTP_OK);
    }

    /**
     * A Notification not worth a call to the gateway: logged with what it
     * named and claimed — not its whole body, which carries the Customer's
     * card details — and heard all the same.
     */
    private function ignored(string $why, Notification $notification): Response
    {
        Log::warning($why, [
            'invoice_reference' => $notification->invoiceReference,
            'gateway_reference' => $notification->gatewayReference,
            'amount' => $notification->amount,
            'currency' => $notification->currency,
        ]);

        return $this->heard();
    }

    /**
     * A Payment answers only at its own method's address: one under another
     * gateway's path is a mistake or a probe, and either way not found.
     */
    private function abortUnlessUnder(string $method, Payment $payment): void
    {
        abort_unless($payment->method->value === $method, ResponseCode::HTTP_NOT_FOUND);
    }
}
