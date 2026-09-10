<?php

namespace App\Support\Reporting;

/**
 * One line of the Delivery mix: a Delivery Option as its label was recorded
 * at Capture, how many Orders chose it, and what it collected. An Order
 * captured before Delivery Options were labelled recorded nothing, and its
 * label is null rather than an invented one; the widget says so.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class DeliveryMixRow
{
    public function __construct(
        /** The label recorded at Capture; null when the Order recorded none. */
        public ?string $label,
        /** The Orders not Cancelled that recorded it. */
        public int $orders,
        /** The Delivery Charge those Orders collected. */
        public int $deliveryCharge,
    ) {}
}
