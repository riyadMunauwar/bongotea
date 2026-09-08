<?php

namespace App\Support\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use LogicException;

/**
 * Cash on Delivery: the courier collects the total at the door.
 *
 * The one Payment Method that needs no credentials, starts no Payment and is
 * never verified — an Order paid this way is simply due. It implements the
 * same contract as every gateway so that the Checkout never special-cases it:
 * Capture asks `isOnline()`, hears no, and sends the Customer to the Thank-you
 * Page.
 *
 * The three calls only an online method ever receives are refused outright
 * rather than answered with something plausible. A caller reaching them has
 * skipped the one question the contract puts first, and a Payment record
 * quietly created for cash would put a Pending row on an Order that owes
 * nothing online.
 */
class CashOnDelivery implements PaymentGateway
{
    public function isOnline(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return [];
    }

    public function takesNotificationUrl(): bool
    {
        return false;
    }

    public function start(Payment $payment, string $returnUrl, ?string $notificationUrl): PaymentStarted
    {
        throw new LogicException('Cash on Delivery starts no Payment: the courier collects at the door.');
    }

    public function paymentReferenceFromReturn(Request $request): ?string
    {
        return null;
    }

    public function paymentReferenceFromNotification(Request $request): ?string
    {
        return null;
    }

    public function verify(Payment $payment): Verification
    {
        throw new LogicException('Cash on Delivery is never verified: there is no gateway to ask.');
    }
}
