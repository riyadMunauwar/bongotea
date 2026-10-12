<?php

namespace App\Support\Reporting;

use App\Models\Customer;

/**
 * One Customer who bought in a window, and what they bought in it.
 *
 * A Customer is only a phone number, so the row is named by the name on
 * their latest Order in the window: names belong to Orders, and one Customer
 * may give a different one each time.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class CustomerRankingRow
{
    public function __construct(
        public Customer $customer,
        /** The name on their latest Order in the window; null where it gave none. */
        public ?string $latestName,
        /** Their Orders in the window counted in Sales. */
        public int $orders,
        public int $kilograms,
        public int $sales,
        /** Whether they had bought before the window began: a Returning Customer, and otherwise a New one. */
        public bool $isReturning,
    ) {}
}
