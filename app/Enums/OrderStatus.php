<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The capture-side lifecycle, and only that.
 *
 * COD fulfilment starts with a confirmation call, and an operator with no way
 * to mark "I have called this" re-calls some customers and misses others — so
 * a chronological list is not enough on day one.
 *
 * `Cancelled` is in the minimum because the most common post-capture outcome
 * is the call failing. Without it, dead orders either clog the queue forever
 * or get deleted, which makes real conversion unknowable.
 *
 * Additive-compatible with any eventual lifecycle: what a later design adds is
 * the tail (packed, shipped, delivered, returned), not a replacement for these.
 *
 * Stored as a string column and cast, never as a MySQL native ENUM: SQLite is
 * the test database and the two dialects diverge on how an enum column is
 * altered.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Confirmed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
