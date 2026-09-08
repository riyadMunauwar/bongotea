<?php

namespace App\Support\Payments;

/**
 * What a gateway answered when asked to start a Payment: where to send the
 * Customer, and the answer itself, kept whole for the record.
 */
final class PaymentStarted
{
    /**
     * @param  array<string, mixed>  $response  The gateway's answer as it arrived.
     */
    public function __construct(
        public readonly string $redirectUrl,
        public readonly array $response = [],
    ) {}
}
