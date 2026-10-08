<?php

namespace App\Support\Orders;

use RuntimeException;

/**
 * Raised when a Cart cannot become an Order as it stands: it is empty, a line
 * names a Product no longer sold or no kilograms, a Product appears twice, or
 * there is no District to deliver to.
 *
 * Never corrected silently. A Capture either sells exactly what was asked
 * for or refuses, so a courier never collects a total nobody agreed to.
 */
class CheckoutUnavailable extends RuntimeException {}
