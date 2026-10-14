<?php

namespace App\Support\Orders;

use App\Support\Pricing\PricedCart;

/**
 * Raised where the Quote a Customer was shown is not what the store charges
 * now: a price moved, or a Product in the Cart is no longer Available, no
 * longer published or no longer sold (ADR-0029), or the Cart wants more of a
 * Product than is Free to Sell (ADR-0037), or a Product's whole weight in it
 * is outside its পাইকারি Order Limit.
 *
 * Carries the figures as they are now — the Cart priced without the
 * Products a buyer cannot have — so the Customer is shown them before
 * anything is placed, rather than told only that something changed. A
 * Product short of stock stays in those figures: the buyer can still have
 * some of it, just not that much.
 */
class QuoteRefused extends CheckoutUnavailable
{
    /**
     * @param  list<int>  $unavailableProductIds  The Products in the Cart a buyer cannot have now.
     * @param  list<int>  $shortProductIds  The Products the Cart wants more of than is Free to Sell.
     * @param  list<int>  $outsideLimitProductIds  The Products the Cart holds less or more of than their Order Limit.
     */
    public function __construct(
        string $message,
        public readonly PricedCart $current,
        public readonly array $unavailableProductIds = [],
        public readonly array $shortProductIds = [],
        public readonly array $outsideLimitProductIds = [],
    ) {
        parent::__construct($message);
    }
}
