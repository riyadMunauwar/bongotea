<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Payments\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

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
     * The Verification, and where to go next: the Thank-you Page once the
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
     * A Payment answers only at its own method's address: one under another
     * gateway's path is a mistake or a probe, and either way not found.
     */
    private function abortUnlessUnder(string $method, Payment $payment): void
    {
        abort_unless($payment->method->value === $method, ResponseCode::HTTP_NOT_FOUND);
    }
}
