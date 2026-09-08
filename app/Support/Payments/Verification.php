<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;

/**
 * What a gateway said when asked, in a call of our own, what became of a
 * Payment.
 *
 * The state is the system's reading of the answer, and the raw response is
 * the answer itself, kept whole: a dispute months later needs what the
 * gateway actually said rather than what was made of it at the time. A
 * gateway asked by our reference may name its own in the answer; it rides
 * along so the Payment can hold it from then on.
 */
final class Verification
{
    /**
     * @param  array<string, mixed>  $response  The gateway's answer as it arrived, for the record.
     * @param  string|null  $gatewayReference  The gateway's own reference for the Payment, where the answer named one.
     */
    public function __construct(
        public readonly PaymentState $state,
        public readonly array $response = [],
        public readonly ?string $gatewayReference = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->state === PaymentState::Paid;
    }
}
