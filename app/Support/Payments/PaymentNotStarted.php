<?php

namespace App\Support\Payments;

use RuntimeException;

/**
 * A gateway could not be asked to start a Payment, or refused to.
 *
 * "Could not start" and never Failed: the Order already exists, nothing has
 * been collected, and the Customer is sent to the Retry Page with their Order
 * kept rather than turned away. What the gateway said, or the transport
 * error in its place, rides along so the attempt's record can keep it.
 */
class PaymentNotStarted extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response  The gateway's answer, or the failure, as it arrived.
     */
    public function __construct(string $message, public readonly array $response = [])
    {
        parent::__construct($message);
    }
}
