<?php

namespace App\Support\Reporting;

use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * One line of the Needs Attention list: an Order, why it is there, and
 * since when. An Order in two conditions at once is two rows, because each
 * is a different thing to do.
 */
final readonly class NeedsAttentionRow
{
    public function __construct(
        public Order $order,
        public AttentionReason $reason,
        /** When the condition began: the capture, the Payment's start, or the last Paid Verification. */
        public CarbonImmutable $since,
    ) {}
}
