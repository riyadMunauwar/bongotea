<?php

namespace App\Support\Orders;

use App\Support\Pricing\PricedRetailCart;

/**
 * Raised where the retail Quote a Customer was shown is not what the store
 * charges now: a Pack Price moved, or a Pack in the packet basket was
 * switched off, removed, named under another Product, or its Product can no
 * longer be had (ADR-0034).
 *
 * Carries the basket priced as it is now, without the Packs a buyer cannot
 * order, so the Customer is shown it before anything is placed.
 */
class RetailQuoteRefused extends CheckoutUnavailable
{
    /**
     * @param  list<int>  $unavailablePackIds  The Packs in the basket a buyer cannot order now.
     */
    public function __construct(
        string $message,
        public readonly PricedRetailCart $current,
        public readonly array $unavailablePackIds = [],
    ) {
        parent::__construct($message);
    }
}
