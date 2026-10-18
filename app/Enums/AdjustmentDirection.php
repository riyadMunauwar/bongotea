<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Which way an Adjustment Cause may move stock (ADR-0040).
 *
 * What stops "two kilograms more, damaged" being written: the dialog offers
 * only the causes that fit the change, and the ledger refuses one that does
 * not. Miscount is the cause that is honestly either.
 */
enum AdjustmentDirection: string implements HasColor, HasLabel
{
    case Adds = 'adds';
    case Removes = 'removes';
    case Either = 'either';

    /**
     * Whether a change of this many grams may carry a cause going this way.
     * A change of nothing fits no cause, because it carries none.
     */
    public function fits(int $difference): bool
    {
        return match ($this) {
            self::Adds => $difference > 0,
            self::Removes => $difference < 0,
            self::Either => $difference !== 0,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Adds => 'Adds stock',
            self::Removes => 'Removes stock',
            self::Either => 'Either way',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Adds => 'success',
            self::Removes => 'danger',
            self::Either => 'gray',
        };
    }
}
