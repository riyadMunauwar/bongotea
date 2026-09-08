<?php

namespace App\Support\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * What every Payment Method is behind, Cash on Delivery included.
 *
 * One contract so that nothing on the Checkout side special-cases a method:
 * Capture asks whether the method is online, starts a Payment if so and sends
 * the Customer where the driver says, and the Processing Page, a Notification
 * and the sweep all verify through the same call. Adding a gateway is one
 * class implementing this and one case on `PaymentMethod` (ADR-0013).
 *
 * Every figure a driver reads is the Payment's own — its amount, its invoice
 * reference, its Order — and every answer it gives comes from a call it made
 * to the gateway itself. What a gateway wrote into the Customer's return URL
 * is read only to find the Payment it is about, never to decide what happened
 * to it.
 */
interface PaymentGateway
{
    /**
     * Whether paying this way sends the Customer to a gateway before the
     * Thank-you Page, and so whether there is ever a Payment to start or to
     * verify. False is the whole of what Cash on Delivery is.
     */
    public function isOnline(): bool;

    /**
     * The names of the credentials this driver needs from the installation's
     * Payment Methods settings, every one of which is a secret there.
     *
     * Empty for a method that talks to no gateway. A method with any of these
     * blank is not usable, whatever its switch says.
     *
     * @return list<string>
     */
    public function credentialProperties(): array;

    /**
     * Whether this gateway takes the Notification URL with every Payment it
     * starts, or has it registered once through its support desk.
     */
    public function takesNotificationUrl(): bool;

    /**
     * Start a Pending Payment at the gateway and say where to send the
     * Customer.
     *
     * The Payment already exists, Pending, for the amount and under the
     * invoice reference the gateway is told; the return URL is the Processing
     * Page the gateway sends the Customer back to whatever happens; the
     * Notification URL is handed over only by a driver that takes one, and is
     * null while the system has no Notification endpoint to name.
     *
     * Throws `PaymentNotStarted` where the gateway could not be reached or
     * refused: a transport failure is "could not start", never Failed.
     */
    public function start(Payment $payment, string $returnUrl, ?string $notificationUrl): PaymentStarted;

    /**
     * The gateway's own reference for the Payment a Customer has been sent
     * back with, read off their return request, or null where the request
     * names none.
     *
     * A reference and nothing more. Whatever else the return carries about
     * the outcome is not believed; `verify()` is what decides.
     */
    public function paymentReferenceFromReturn(Request $request): ?string;

    /**
     * The gateway's own reference for the Payment a Notification is about,
     * or null where the request names none.
     *
     * A hint that a Verification is worth making, and never an answer: a
     * Notification records nothing by itself.
     */
    public function paymentReferenceFromNotification(Request $request): ?string;

    /**
     * Ask the gateway, in a call of our own, what became of a Payment.
     *
     * Asked by the gateway's reference where the Payment holds one, and by
     * our invoice reference otherwise, so a Customer who cancelled before
     * anything was attempted is still answered for. Paid only where the
     * gateway confirms it, names this Payment, and took the amount asked
     * for. A gateway that cannot be reached answers still Pending, never
     * Failed, so a moment's outage is retried by the sweep rather than
     * sending a Customer who paid to the Retry Page.
     */
    public function verify(Payment $payment): Verification;
}
