<?php

namespace App\Support\Orders;

use App\Support\Pricing\PricedCart;

/**
 * Raised where the Quote a Customer was shown is not what the store charges
 * now: a price moved, or a Product in the Cart is no longer Available, no
 * longer published or no longer sold (ADR-0029).
 *
 * Carries the figures as they are now — the Cart priced without the
 * Products a buyer cannot have — so the Customer is shown them before
 * anything is placed, rather than told only that something changed.
 */
class QuoteRefused extends CheckoutUnavailable
{
    /**
     * @param  list<int>  $unavailableProductIds  The Products in the Cart a buyer cannot have now.
     */
    public function __construct(
        string $message,
        public readonly PricedCart $current,
        public readonly array $unavailableProductIds = [],
    ) {
        parent::__construct($message);
    }
}
