<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

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
enum PaymentState: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /**
     * Paid is the one answer that settles the money; Pending is the one still
     * worth asking about; the other three all mean "no money moved here" and
     * differ only in whose doing it was, so they share a colour.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Failed, self::Cancelled, self::Expired => 'gray',
        };
    }

    /**
     * Whether this is where a Payment ends: anything but Pending. A settled
     * Payment is never asked about again.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
