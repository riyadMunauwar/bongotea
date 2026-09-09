<?php

namespace Tests\Fixtures\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;

/**
 * One gateway's documented request and response shapes, scripted for the
 * faked HTTP client, so that the online payment tests run once per gateway
 * as a dataset over the enum and a later gateway adds a row rather than a
 * file.
 *
 * Every method here speaks in the gateway's own terms: what it answers when
 * a Payment is started, what it posts the Customer back with, and what it
 * says when asked. What the system makes of those is what the tests assert.
 */
abstract class GatewayScript
{
    abstract public function method(): PaymentMethod;

    /** Switch the method on for the installation with credentials filled, the way a Merchant would. */
    abstract public function makeUsable(): void;

    /** Where the gateway sends a Customer to pay. */
    abstract public function gatewayPageUrl(): string;

    /** The gateway's own reference for this Payment, the one the Customer comes back with; distinct per Payment, as a gateway's are. */
    abstract public function gatewayReference(Payment $payment): string;

    /** Fake the gateway accepting a Payment and answering with its page. */
    abstract public function fakeStart(): void;

    /**
     * What the gateway answered when this Payment was started, for a Payment
     * a test made without a Capture: the record a driver may need to ask
     * about it by (bKash asks by the id its create issued).
     *
     * @return array<string, mixed>
     */
    abstract public function startResponseFor(Payment $payment): array;

    /** Fake the gateway refusing to start a Payment. */
    abstract public function fakeStartRefused(): void;

    /** Fake the gateway being unreachable for every call. */
    abstract public function fakeOutage(): void;

    /** Assert the start request carried the mandatory fields for this Payment, filled from its Order, and the Notification URL where the gateway takes one per request. */
    abstract public function assertStartedFor(Payment $payment, string $returnUrl, string $notificationUrl): void;

    /** Assert the gateway was asked to start a Payment exactly this many times since the last fake was laid. */
    abstract public function assertStartsSent(int $count): void;

    /** Assert the start request carried every field the gateway calls mandatory filled, for a Payment whose Checkout asked for a phone and nothing else. */
    abstract public function assertStartedWithEveryMandatoryFieldFilled(Payment $payment): void;

    /**
     * What the gateway posts to the Notification URL on its own once the
     * Payment was taken: the same story as the return, told to the system
     * rather than to the browser.
     *
     * @return array<string, string>
     */
    abstract public function paidNotification(Payment $payment): array;

    /**
     * A Notification naming a Payment the system does not know.
     *
     * @return array<string, string>
     */
    abstract public function notificationNamingNoPayment(): array;

    /**
     * A Notification naming this Payment but claiming a different amount.
     *
     * @return array<string, string>
     */
    abstract public function notificationWithMismatchedAmount(Payment $payment): array;

    /**
     * What the gateway posts the Customer back to the Processing Page with.
     *
     * @return array<string, string>
     */
    abstract public function paidReturn(Payment $payment): array;

    /**
     * @return array<string, string>
     */
    abstract public function failedReturn(Payment $payment): array;

    /**
     * A return the Customer cancelled: it names the Payment by our reference
     * only, because nothing at the gateway was ever attempted.
     *
     * @return array<string, string>
     */
    abstract public function cancelledReturn(Payment $payment): array;

    /** Fake the gateway confirming the Payment for its full amount when asked, by its own reference or by ours. */
    abstract public function fakeVerifiedPaid(Payment $payment): void;

    /** Fake the gateway, asked by our reference, having nothing decided about the Payment yet. */
    abstract public function fakeVerifiedStillPending(Payment $payment): void;

    /** Fake the gateway confirming a Payment for a smaller amount than asked for. */
    abstract public function fakeVerifiedShort(Payment $payment): void;

    /** Fake the gateway saying the reference is not a valid transaction, and, asked by our reference, that the attempt failed. */
    abstract public function fakeVerifiedInvalid(Payment $payment): void;

    /** Fake the gateway answering, for the reference the Customer came back with, some other Payment's transaction — and, asked by our reference, that ours was taken. */
    abstract public function fakeVerifiedForAnotherPayment(Payment $payment): void;

    /** Fake the gateway saying, when asked by our reference, that the Customer cancelled. */
    abstract public function fakeVerifiedCancelled(Payment $payment): void;

    /** Assert the gateway was asked about the Payment exactly this many times. */
    abstract public function assertVerificationsSent(int $count): void;

    /**
     * How many questions the driver asks before settling a Payment whose
     * return carried another transaction's reference: two for a gateway
     * asked first by that reference and then by ours, one for a gateway
     * with a single question by our reference alone.
     */
    public function questionsToSettlePastAnotherReference(): int
    {
        return 2;
    }

    /**
     * Our invoice reference as the recorded Verification answer names it, in
     * the gateway's own field, or null where the answer names none.
     *
     * @param  array<string, mixed>  $verificationResponse
     */
    abstract public function invoiceReferenceNamedBy(array $verificationResponse): ?string;
}
