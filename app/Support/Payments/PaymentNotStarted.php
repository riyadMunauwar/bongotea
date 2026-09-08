<?php

namespace App\Support\Payments;

use RuntimeException;

/**
 * A gateway could not be asked to start a Payment, or refused to.
 *
 * "Could not start" and never Failed: the Order already exists, nothing has
 * been collected, and the Customer is sent to the Retry Page with their Order
 * kept rather than turned away.
 */
class PaymentNotStarted extends RuntimeException {}
