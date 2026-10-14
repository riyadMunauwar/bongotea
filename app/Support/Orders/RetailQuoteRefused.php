<?php

namespace App\Support\Orders;

use App\Support\Pricing\PricedRetailCart;

/**
 * Raised where the retail Quote a Customer was shown is not what the store
 * charges now: a Pack Price moved, or a Pack in the packet basket was
 * switched off, removed, named under another Product, or its Product can no
 * longer be had (ADR-0034), or the basket wants more of a Product than is
 * Free to Sell (ADR-0037), or a Product's Packs together weigh less or more
 * than its খুচরা Order Limit.
 *
 * Carries the basket priced as it is now, without the Packs a buyer cannot
 * order, so the Customer is shown it before anything is placed.
 */
class RetailQuoteRefused extends CheckoutUnavailable
{
    /**
     * @param  list<int>  $unavailablePackIds  The Packs in the basket a buyer cannot order now.
     * @param  list<int>  $shortPackIds  The Packs whose Product the basket wants more of than is Free to Sell.
     * @param  list<int>  $outsideLimitPackIds  The Packs whose Product the basket holds less or more of than its Order Limit.
     */
    public function __construct(
        string $message,
        public readonly PricedRetailCart $current,
        public readonly array $unavailablePackIds = [],
        public readonly array $shortPackIds = [],
        public readonly array $outsideLimitPackIds = [],
    ) {
        parent::__construct($message);
    }
}
