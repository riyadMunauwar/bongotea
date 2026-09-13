<?php

namespace App\Support\Couriers;

use App\Enums\Courier;

/**
 * One Courier's two money figures, side by side and never added together.
 *
 * The balance is what that Courier says it is holding, or the reason there is
 * no figure; COD in Flight is what we worked out ourselves from the parcels
 * it is carrying. They answer different questions — one is a Courier's word
 * about an account, the other is the Merchant's own Orders — and a single
 * number made of both would be a figure nobody could reconcile against
 * anything.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class CourierMoneyRow
{
    public function __construct(
        public Courier $courier,
        /** What this Courier reports it is holding, or why there is no figure. */
        public CourierBalance $balance,
        /** What this Courier's Active Shipments were sent out to collect. */
        public int $codInFlight,
    ) {}
}
