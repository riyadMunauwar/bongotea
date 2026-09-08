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

    /** Fake the gateway refusing to start a Payment. */
    abstract public function fakeStartRefused(): void;

    /** Fake the gateway being unreachable for every call. */
    abstract public function fakeOutage(): void;

    /** Assert the start request carried the mandatory fields for this Payment, filled from its Order. */
    abstract public function assertStartedFor(Payment $payment, string $returnUrl): void;

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

    /** Fake the gateway confirming the Payment for its full amount when asked. */
    abstract public function fakeVerifiedPaid(Payment $payment): void;

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
}
