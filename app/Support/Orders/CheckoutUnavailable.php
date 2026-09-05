<?php

namespace App\Support\Orders;

use RuntimeException;

/**
 * Raised when a Capture is attempted against a Page with nothing left to sell,
 * or for a Product the submission gave no quantity for.
 *
 * Normally unreachable: publishing a Page with no linked Product is blocked,
 * and the request is validated before it gets here. It exists for the window in
 * between — a Product soft-deleted or newly linked between the visitor loading
 * a cached page and submitting it.
 */
class CheckoutUnavailable extends RuntimeException {}
