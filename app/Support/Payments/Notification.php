<?php

namespace App\Support\Payments;

use App\Models\Payment;

/**
 * What a gateway said, on its own, about a Payment: read off a Notification
 * to find the Payment it names and to refuse one that disagrees with the
 * record, and for nothing else.
 *
 * A hint and never an answer. It arrived on an unauthenticated POST to an
 * address anyone can guess, so what it claims happened is not believed; a
 * Verification the system makes decides, and this only says which Payment
 * to ask about. An amount is read for the one reason the record can check
 * it: a Notification naming a figure that is not the Payment's is about
 * something else, or about nothing, and is not worth a call.
 */
final class Notification
{
    /**
     * @param  string  $invoiceReference  Our reference for the Payment, the one the gateway was told at the start.
     * @param  string|null  $gatewayReference  The gateway's own reference, where the Notification carries one; what a Verification then asks by.
     * @param  int|null  $amount  The amount the Notification claims, in minor units, where it names one.
     * @param  string|null  $currency  The money that amount is in, where the Notification says.
     */
    public function __construct(
        public readonly string $invoiceReference,
        public readonly ?string $gatewayReference = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
    ) {}

    /**
     * Whether the figure the Notification claims is the Payment's own. A
     * Notification naming no figure disagrees with nothing.
     */
    public function claimsAmountOf(Payment $payment): bool
    {
        if ($this->amount === null) {
            return true;
        }

        return $this->amount === $payment->amount
            && ($this->currency === null || $this->currency === $payment->currency);
    }
}
