<?php

namespace App\Support\Orders;

use RuntimeException;

/**
 * Raised when a Capture is attempted against a Page that has nothing
 * unambiguously for sale.
 *
 * Normally unreachable: publishing such a Page is blocked, and the request is
 * validated before it gets here. It exists for the window in between — a
 * Product soft-deleted, or a second one linked, between the visitor loading a
 * cached page and submitting it.
 */
class CheckoutUnavailable extends RuntimeException {}
