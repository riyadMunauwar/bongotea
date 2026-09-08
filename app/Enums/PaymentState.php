<?php

namespace App\Enums;

/**
 * Where one Payment stands: one attempt to collect an Order's total through
 * one online Payment Method.
 *
 * Its own axis, separate from whether the Order is New, Confirmed or
 * Cancelled, and neither ever overwrites the other (ADR-0013). Pending is the
 * only state a Payment starts in; Paid is the only one a Verification the
 * system made itself can put it in; Expired is a Pending Payment older than a
 * day that still cannot be verified.
 *
 * Named now because the gateway contract answers a Verification in these
 * words. The Payment record that stores one of them arrives with the online
 * methods.
 */
enum PaymentState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
