<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an Enquiry stands: nobody has called yet, somebody has called back,
 * or there is nothing more to do about it.
 *
 * Stored as a string column and cast, like `OrderStatus`.
 */
enum EnquiryStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Replied = 'replied';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Replied => 'Replied',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Replied => 'info',
            self::Closed => 'gray',
        };
    }
}
