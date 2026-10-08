<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The kinds of text message a Customer is sent about their Order: it was
 * placed, confirmed, sent with a Courier, or Cancelled.
 *
 * Each is switched on and worded in `SmsSettings` under its value in camel
 * case, so `placedEnabled` and `placedTemplate`; a kind is a case here and
 * two properties there, and nothing else lists them.
 */
enum OrderText: string implements HasLabel
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Placed => 'Order placed',
            self::Confirmed => 'Order confirmed',
            self::Sent => 'Sent with a Courier',
            self::Cancelled => 'Order cancelled',
        };
    }

    /**
     * The text an Order moving to this status is owed, or null for a status
     * that owes none.
     */
    public static function forStatus(OrderStatus $status): ?self
    {
        return match ($status) {
            OrderStatus::Confirmed => self::Confirmed,
            OrderStatus::Cancelled => self::Cancelled,
            OrderStatus::New => null,
        };
    }
}
