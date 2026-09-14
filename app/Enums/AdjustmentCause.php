<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Why the ledger was out by what a Stock Count found.
 *
 * Picked from a fixed list rather than typed, because the question a Merchant
 * asks is "how much am I losing to damage" — asked of a year of Adjustments at
 * once, which only answers if the same thing is spelled the same way every
 * time. The note beside it is where anything that does not fit goes.
 *
 * Compulsory, including on the first count of a Product: **Opening count** is
 * what that one is for, and it is the whole of the setup this system asks for.
 * There is no seed, no migration and no screen — a Product joins the ledger
 * when somebody counts it and not before.
 */
enum AdjustmentCause: string implements HasColor, HasLabel
{
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Found = 'found';
    case Miscount = 'miscount';
    case OpeningCount = 'opening_count';

    public function getLabel(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::Lost => 'Lost',
            self::Found => 'Found',
            self::Miscount => 'Miscount',
            self::OpeningCount => 'Opening count',
        };
    }

    /**
     * Stock gone is read as a loss, stock arriving as a gain, and the two
     * that are about the ledger being wrong rather than about the shelf
     * changing are drawn as neither.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Damaged, self::Lost => 'danger',
            self::Found => 'success',
            self::Miscount, self::OpeningCount => 'gray',
        };
    }
}
