<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Stored as a string column and cast, never as a MySQL native ENUM: SQLite is
 * the test database and the two dialects diverge on how an enum column is
 * altered.
 */
enum PageStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Published = 'published';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
