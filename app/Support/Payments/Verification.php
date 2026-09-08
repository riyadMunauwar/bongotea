<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;

/**
 * What a gateway said when asked, in a call of our own, what became of a
 * Payment.
 *
 * The state is the system's reading of the answer, and the raw response is
 * the answer itself, kept whole: a dispute months later needs what the
 * gateway actually said rather than what was made of it at the time.
 */
final class Verification
{
    /**
     * @param  array<string, mixed>  $response  The gateway's answer as it arrived, for the record.
     */
    public function __construct(
        public readonly PaymentState $state,
        public readonly array $response = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->state === PaymentState::Paid;
    }
}
