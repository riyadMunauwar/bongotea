<?php

namespace App\Support\Payments;

use RuntimeException;

/**
 * A Payment could not be started because another on the same Order is
 * Pending and only seconds old.
 *
 * Refused before the gateway is asked and before anything is written: a
 * Customer tapping again moments after being sent to pay is about to pay
 * twice, and the one thing to do is tell them so on the Retry Page. The
 * message is worded for the Customer, because that is who reads it, and it
 * is all the refusal carries.
 */
class PaymentStillPending extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A payment you started a moment ago is still being confirmed. Please wait a minute before trying again.');
    }
}
